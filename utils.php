<?php
/** @noinspection PhpUndefinedConstantInspection */
/** @noinspection PhpComposerExtensionStubsInspection */

require_once __DIR__ . '/vendor/autoload.php';

use InstagramAPI\Exception\ChallengeRequiredException;
use InstagramAPI\Instagram;

class Utils
{
    public static function checkForUpdate(string $current, string $flavor): bool
    {
        if ($flavor == "custom") {
            return false;
        }
        return (int)json_decode(file_get_contents("https://raw.githubusercontent.com/JRoy/InstagramLive-PHP/update/$flavor.json"), true)['versionCode'] > (int)$current;
    }

    /**
     * Sanitizes a stream key for clip command on Windows.
     * @param string $streamKey The stream key to sanitize.
     * @return string The sanitized stream key.
     */
    public static function sanitizeStreamKey($streamKey): string
    {
        return str_replace("&", "^^^&", $streamKey);
    }

    /**
     * Logs information about the current environment.
     * @param string $exception Exception message to log.
     */
    public static function dump(string $exception = null)
    {
        clearstatcache();
        self::log("===========BEGIN DUMP===========");
        self::log("InstagramLive-PHP Version: " . scriptVersion);
        self::log("InstagramLive-PHP Flavor: " . scriptFlavor);
        self::log("Operating System: " . PHP_OS);
        self::log("PHP Version: " . PHP_VERSION);
        self::log("PHP Runtime: " . php_sapi_name());
        self::log("PHP Binary: " . PHP_BINARY);
        self::log("Bypassing OS-Check: " . (bypassCheck == true ? "true" : "false"));
        self::log("Composer Lock: " . (file_exists("composer.lock") == true ? "true" : "false"));
        self::log("Vendor Folder: " . (file_exists("vendor/") == true ? "true" : "false"));
        if ($exception !== null) {
            self::log("Exception: " . $exception);
        }
        self::log("============END DUMP============");
    }

    /**
     * Helper function to check if the current OS is Windows.
     * @return bool Returns true if running Windows.
     */
    public static function isWindows(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    /**
     * Logs message to a output file.
     * @param string $message message to be logged to file.
     */
    public static function logOutput($message)
    {
        file_put_contents('output.txt', $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Checks for a file existance, if it doesn't exist throw a dump and exit the script.
     * @param $path string Path to the file.
     * @param $reason string Reason the file is needed.
     */
    public static function existsOrError($path, $reason)
    {
        if (!file_exists($path)) {
            self::log("The following file, `" . $path . "` is required and not found by the script for the following reason: " . $reason);
            self::log("Please make sure you follow the setup guide correctly.");
            self::dump();
            exit();
        }
    }

    /**
     * Checks to see if characters are at the start of the string.
     * @param string $haystack The string to for the needle.
     * @param string $needle The string to search for at the start of haystack.
     * @return bool Returns true if needle is at start of haystack.
     */
    public static function startsWith($haystack, $needle)
    {
        return (substr($haystack, 0, strlen($needle)) === $needle);
    }

    public static function loginFlow($username, $password): ExtendedInstagram
    {
        $ig = new ExtendedInstagram(false, false);
        try {
            $loginResponse = $ig->login($username, $password);

            if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {
                self::log("Two-Factor Authentication Required! Please provide your verification code from your texts/other means.");
                $twoFactorIdentifier = $loginResponse->getTwoFactorInfo()->getTwoFactorIdentifier();
                print "\nType your verification code> ";
                $handle = fopen("php://stdin", "r");
                $verificationCode = trim(fgets($handle));
                fclose($handle);
                self::log("Logging in with verification token...");
                $ig->finishTwoFactorLogin($username, $password, $twoFactorIdentifier, $verificationCode);
            }
        } catch (\Exception $e) {
            try {
                /** @noinspection PhpUndefinedMethodInspection */
                if ($e instanceof ChallengeRequiredException && $e->getResponse()->getErrorType() === 'checkpoint_challenge_required') {
                    $response = $e->getResponse();

                    self::log("Suspicious Login: Would you like to verify your account via text or email? Type \"yes\" or just press enter to ignore.");
                    self::log("Suspicious Login: Please only attempt this once or twice if your attempts are unsuccessful. If this keeps happening, this script is not for you :(.");
                    print "> ";
                    $handle = fopen("php://stdin", "r");
                    $attemptBypass = trim(fgets($handle));
                    fclose($handle);
                    if ($attemptBypass !== 'yes') {
                        self::log("Suspicious Login: Account Challenge Failed :(.");
                        self::dump();
                        exit();
                    }
                    self::log("Preparing to verify account...");
                    sleep(3);

                    self::log("Suspicious Login: Please select your verification option by typing \"sms\" or \"email\" respectively. Otherwise press enter to abort.");
                    print "> ";
                    $handle = fopen("php://stdin", "r");
                    $choice = trim(fgets($handle));
                    fclose($handle);
                    if ($choice === "sms") {
                        $verification_method = 0;
                    } elseif ($choice === "email") {
                        $verification_method = 1;
                    } else {
                        self::log("Aborting!");
                        exit();
                    }

                    /** @noinspection PhpUndefinedMethodInspection */
                    $checkApiPath = trim(substr($response->getChallenge()->getApiPath(), 1));
                    $customResponse = $ig->request($checkApiPath)
                        ->setNeedsAuth(false)
                        ->addPost('choice', $verification_method)
                        ->addPost('_uuid', $ig->uuid)
                        ->addPost('guid', $ig->uuid)
                        ->addPost('device_id', $ig->device_id)
                        ->addPost('_uid', $ig->account_id)
                        ->addPost('_csrftoken', $ig->client->getToken())
                        ->getDecodedResponse();

                    try {
                        if ($customResponse['status'] === 'ok' && isset($customResponse['action'])) {
                            if ($customResponse['action'] === 'close') {
                                self::log("Suspicious Login: Account challenge successful, please re-run the script!");
                                exit();
                            }
                        }

                        self::log("Please enter the code you received via " . ($verification_method ? 'email' : 'sms') . "...");
                        print "> ";
                        $handle = fopen("php://stdin", "r");
                        $cCode = trim(fgets($handle));
                        fclose($handle);
                        $ig->changeUser($username, $password);
                        $customResponse = $ig->request($checkApiPath)
                            ->setNeedsAuth(false)
                            ->addPost('security_code', $cCode)
                            ->addPost('_uuid', $ig->uuid)
                            ->addPost('guid', $ig->uuid)
                            ->addPost('device_id', $ig->device_id)
                            ->addPost('_uid', $ig->account_id)
                            ->addPost('_csrftoken', $ig->client->getToken())
                            ->getDecodedResponse();

                        if (@$customResponse['status'] === 'ok' && @$customResponse['logged_in_user']['pk'] !== null) {
                            self::log("Suspicious Login: Account challenge successful, please re-run the script!");
                            exit();
                        } else {
                            self::log("Suspicious Login: I have no clue if that just worked, re-run me to check.");
                            exit();
                        }
                    } catch (Exception $ex) {
                        self::log("Suspicious Login: Account Challenge Failed :(.");
                        self::dump($ex->getMessage());
                        exit();
                    }
                }
            } catch (\LazyJsonMapper\Exception\LazyJsonMapperException $mapperException) {
                self::log("Error While Logging in to Instagram: " . $e->getMessage());
                self::dump();
                exit();
            }

            self::log("Error While Logging in to Instagram: " . $e->getMessage());
            self::dump();
            exit();
        }
        return $ig;
    }

    /**
     * Logs a message in console but it actually uses new lines.
     * @param string $message message to be logged.
     */
    public static function log($message)
    {
        print $message . "\n";
    }
}

class ExtendedInstagram extends Instagram
{
    public function changeUser($username, $password)
    {
        $this->_setUser($username, $password);
    }
}