<?php


namespace App;

class Configuration {
    public $bank_username;
    public $bank_password;
    public $bank_url;
    public $bank_code;
    public $bank_2fa;
    public $bank_2fa_device;
    public $bank_fints_persistence;
    public $firefly_url;
    public $firefly_access_token;
    public $skip_transaction_review;
    public $bank_account_iban;
    public $firefly_account_id;
    public $choose_account_from;
    public $choose_account_to;
    public $description_regex_match;
    public $description_regex_replace;
    public $force_mt940;
    public $auto_save_persistence;
}

class ConfigurationFactory
{
    /**
     * Write the current persistence token back into the config file, but only if the user opted in
     * via the `auto_save_persistence` flag. Returns true if a token was written.
     */
    static function save_persistence($session): bool
    {
        if (!$session->get('auto_save_persistence')) {
            return false;
        }
        $persisted_fints = $session->get('persistedFints');
        $config_file     = $session->get('config_file');
        if ($persisted_fints && $config_file) {
            self::update_persistence_token($config_file, $persisted_fints);
            return true;
        }
        return false;
    }

    /**
     * Low-level writer: set or remove the `bank_fints_persistence` field in the given config file.
     * Passing null/'' for $token removes the field.
     */
    static function update_persistence_token($fileName, $token)
    {
        $jsonFileContent = file_get_contents($fileName);
        $contentArray    = json_decode($jsonFileContent, true);
        if ($token !== null && $token !== '') {
            $contentArray['bank_fints_persistence'] = base64_encode($token);
        } else {
            unset($contentArray['bank_fints_persistence']);
        }
        file_put_contents(
            $fileName,
            json_encode($contentArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    static function load_from_file($fileName)
    {
        $jsonFileContent = file_get_contents($fileName);
        $contentArray = json_decode($jsonFileContent, true);

        $configuration = new Configuration();
        $configuration->bank_username           = $contentArray["bank_username"];
        $configuration->bank_password           = $contentArray["bank_password"];
        $configuration->bank_url                = $contentArray["bank_url"];
        $configuration->bank_code               = $contentArray["bank_code"];
        $configuration->bank_2fa                = $contentArray["bank_2fa"];
        $configuration->bank_2fa_device         = @$contentArray["bank_2fa_device"];
        if (isset($contentArray["bank_fints_persistence"]) && $contentArray["bank_fints_persistence"] != '') {
            $configuration->bank_fints_persistence = base64_decode($contentArray["bank_fints_persistence"]);
        }
        $configuration->firefly_url             = $contentArray["firefly_url"];
        $configuration->firefly_access_token    = $contentArray["firefly_access_token"];
        $configuration->skip_transaction_review = filter_var($contentArray["skip_transaction_review"], FILTER_VALIDATE_BOOLEAN);
        if (isset($contentArray["choose_account_automation"])) {
            $configuration->bank_account_iban       = $contentArray["choose_account_automation"]["bank_account_iban"];
            $configuration->firefly_account_id      = $contentArray["choose_account_automation"]["firefly_account_id"];
            $configuration->choose_account_from     = $contentArray["choose_account_automation"]["from"];
            $configuration->choose_account_to       = $contentArray["choose_account_automation"]["to"];
        } else {
            $configuration->bank_account_iban = NULL;
            $configuration->firefly_account_id = NULL;
            $configuration->choose_account_from = NULL;
            $configuration->choose_account_to = NULL;
        }
        $configuration->description_regex_match   = $contentArray["description_regex_match"];
        $configuration->description_regex_replace = $contentArray["description_regex_replace"];
        $configuration->force_mt940               = filter_var($contentArray["force_mt940"] ?? false, FILTER_VALIDATE_BOOLEAN);
        $configuration->auto_save_persistence     = filter_var($contentArray["auto_save_persistence"] ?? false, FILTER_VALIDATE_BOOLEAN);

        return $configuration;
    }
}
