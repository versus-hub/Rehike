<?php
namespace Rehike\SignInV2;

use Rehike\SignInV2\{
    SessionErrors,
    Builder\SessionInfoBuilder,
    Info\SessionInfo,
    Parser\SwitcherParser
};

use Rehike\Network;
use Rehike\Logging\DebugLogger;

use Rehike\Async\Promise;
use function Rehike\Async\async;

use Exception;
use Rehike\Helper\ChannelUtils;

/**
 * Manages Google Account (GAIA) authentication and initialization.
 * 
 * @author Isabella Lulamoon <kawapure@gmail.com>
 * @author Taniko Yamamoto <kirasicecreamm@gmail.com>
 * @author The Rehike Maintainers
 */
class GaiaAuthManager
{
    /**
     * Stores the user's "SAPISID" session cookie, which plays a crucial role
     * in authentication.
     * 
     * If the cookie is unavailable, then this will be unset.
     */
    private static string $sapisid = "";
    
    /**
     * Stores the user's "LOGIN_INFO" session cookie. This changes whenever the
     * user's session changes, so we use this to check if we should reject the
     * current cache.
     */
    private static string $loginInfoCookie = "";

    public static function __initStatic(): void
    {
        if (isset($_COOKIE) && isset($_COOKIE["SAPISID"]) && isset($_COOKIE["LOGIN_INFO"]))
        {
            self::$sapisid = $_COOKIE["SAPISID"];
            self::$loginInfoCookie = $_COOKIE["LOGIN_INFO"];
        }
    }

    /**
     * Checks if GAIA authentication should be attempted.
     * 
     * If this function returns false, all GAIA-related behavior should be
     * ignored.
     */
    public static function shouldAttemptAuth(): bool
    {
        return isset(self::$sapisid);
    }

    /**
     * Generates a new SAPISIDHASH for the current timestamp, which is used in
     * the HTTP Authorization header.
     */
    public static function generateSapisidHash(string $origin = "https://www.youtube.com"): string
    {
        $sapisid = self::$sapisid;

        $time = time();
        $sha1 = sha1("{$time} {$sapisid} {$origin}");

        return "SAPISIDHASH {$time}_{$sha1}";
    }

    /**
     * Newly requests session data from YouTube's servers.
     * 
     * For performance reasons, the result will be cached and reused for some
     * time after the initial request.
     * 
     * @return Promise<SessionInfo>
     */
    public static function getFreshInfoFromRemote(): Promise/*<SessionInfo>*/
    {
        return async(function() {
            $infoBuilder = new SessionInfoBuilder();

            try
            {
                $accSwitcher = yield self::requestAccountSwitcherData();

                // If the user is signed out, then do not process further.
                if (null == $accSwitcher)
                {
                    return $infoBuilder->build();
                }

                $switcherParser = new SwitcherParser($infoBuilder, $accSwitcher);
                $switcherParser->parse();
                
                try
                {
                    $ucid = yield self::requestUcid();
                    $infoBuilder->activeChannelBuilder->ucid = $ucid;
                }
                catch (Exception $e)
                {
                    // cancel build
                    $infoBuilder->pushSessionError(-1);
                    return $infoBuilder->build();
                }

                return $infoBuilder->build();
            }
            catch (Exception $e)
            {
                DebugLogger::print("[GaiaAuthManager::getFreshInfoFromRemote] %s", $e->getMessage());
                $infoBuilder->pushSessionError(SessionErrors::FAILED_REQUEST);
                return $infoBuilder->build();
            }
        });
    }

    /**
     * Requests account switcher data from the server for parsing.
     * 
     * As this is not a standard InnerTube endpoint, it features a JSON buster
     * that must be removed or it will break the JSON parser.
     * 
     * @return Promise<?object> Account switcher data if signed in, null if signed out.
     */
    private static function requestAccountSwitcherData(): Promise/*<?object>*/
    {
        return async(function() {
            // We don't want the request to follow the location of this request,
            // since this will redirect to the Google Account sign in page if
            // the user is not signed in, and throw an exception.
            $response = yield Network::urlRequestFirstParty(
                "https://www.youtube.com/getAccountSwitcherEndpoint",
                Network::getDefaultYoutubeOpts() + [
                    "redirect" => "manual"
                ]
            );

            // 3xx status means redirect. If we get redirected, simply assume
            // that the user is not signed in.
            if ($response->status > 299 && $response->status < 400)
            {
                return null;
            }

            // Remove the JSON buster.
            $object = @json_decode(
                substr($response->getText(), 4)
            );

            // If any other failure status is returned by the server, or if the
            // data we received is invalid, then throw an exception.
            if ($response->status != 200 || !is_object($object))
            {
                throw new Exception(
                    "Account switcher data is invalid."
                );
            }

            return $object;
        });
    }
    
    /**
     * 
     * 
     * @return Promise<string>
     */
    private static function requestUcid(): Promise/*<string>*/
    {
        return async(function() {
            $profileResponseRaw = yield Network::innertubeRequest(
                action: "navigation/resolve_url",
                body: [
                    "url" => "https://www.youtube.com/profile"
                ]
            );
            
            $profileResponse = $profileResponseRaw->getJson();
            
            $endpoint = $profileResponse->endpoint->urlEndpoint->url;
            
            if (strpos($endpoint, "/channel/") !== false)
            {
                $ucid = explode("/channel/", $endpoint)[1];
                
                return $ucid;
            }
            
            return yield ChannelUtils::getUcid($endpoint);
        });
    }
}