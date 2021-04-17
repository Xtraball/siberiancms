<?php

namespace Siberian;

use Siberian\ACME\Cert;

/**
 * Class Siberian_Cron
 *
 * @author Xtraball SAS <dev@xtraball.com>
 *
 * @version 4.16.0
 */
class Cron
{
    /**
     * @var \Cron_Model_Cron
     */
    protected $cron;

    /**
     * @var \Siberian_Log
     */
    protected $logger;

    /**
     * @var String
     */
    protected $lock_base = "/var/tmp/";

    /**
     * @var String
     */
    protected $root_path;

    /**
     * @var array
     */
    protected $locked_tasks = [];

    /**
     * @var integer
     */
    protected $start;

    /**
     * Siberian_Cron constructor.
     * @throws \Zend_Exception
     */
    public function __construct()
    {
        $this->cron = new \Cron_Model_Cron();
        $this->logger = \Zend_Registry::get('logger');
        $this->lock_base = path($this->lock_base);
        $this->start = microtime(true);
        $this->root_path = path();

        # Set the same timezone as in the Application settings.
        $timezone = __get("system_timezone");
        if ($timezone) {
            date_default_timezone_set($timezone);
        }
    }

    /**
     * @param $text
     */
    public function log($text)
    {
        echo sprintf("[CRON: %s]: %s\n", date("Y-m-d H:i:s"), $text);
    }

    /**
     * @return $this|array|bool
     */
    public function triggerAll()
    {
        if (!\Cron_Model_Cron::is_active()) {
            $this->log("Cron is disabled in your system, see: Backoffice > Settings > Advanced > Configuration > Cron");
            return $this;
        }

        try {
            $minute = (int) date("i");
            $hour = (int) date("G");
            $month_day = (int) date("j");
            $month = (int) date('m');
            $week_day = (int) date('w');

            $all = $this->cron->getActiveActions($minute, $hour, $month_day, $month, $week_day);

            $actions = [];

            foreach ($all as $task) {
                $actions[] = [
                    "id" => $task->getCommand(),
                    "command" => $task->getCommand(),
                ];
                $this->execute($task);
            }

            return $actions;
        } catch (\Exception $e) {
            if (APPLICATION_ENV === 'development') {
                \Zend_Debug::dump($e);
            }
            $this->log($e->getMessage());
            return false;
        }
    }

    /**
     * @param $command
     */
    public function runTaskByCommand($command)
    {
        try {
            $tasks = $this->cron->getTaskByCommand($command);
            foreach ($tasks as $task) {
                if (!$task->getId()) {
                    throw new \Siberian\Exception('The task doesn\'t exists.');
                }
                $this->execute($task);
            }
        } catch (Exception $e) {
            $this->log('[runTaskByCommand::ERROR]: ' . $e->getMessage());
        }
    }

    /**
     * @param \Cron_Model_Cron $task
     */
    public function execute($task)
    {
        /** Avoid duplicates when a task takes too long */
        $task->trigger();
        $success = true;

        if (!$this->isLocked($task->getId())) {
            $this->log("Executing task: " . $task->getName());

            /** Non blocking tasks */
            try {
                $command = $task->getCommand();
                if (strpos($command, "::") !== false) {
                    $this->log("Class method to run {$command}");

                    # Split Class::method
                    $parts = explode("::", $command);
                    $class = $parts[0];
                    $method = $parts[1];

                    # Tests.
                    if (method_exists($class, $method)) {
                        $this->log("Method exists {$command}");
                        call_user_func($command, $this, $task);
                    }

                } else {
                    # Local method
                    if (method_exists($this, $command)) {
                        $this->$command($task);
                    }
                }

            } catch (Exception $e) {
                $this->log($e->getMessage());

                // Unlock task in case of Exception!
                $this->unlock($task->getId());

                $task->saveLastError($e->getMessage());

                $success = false;
            }
        } else {
            $this->log("Locked task: {$task->getName()}, skipping...");
        }

        if ($success) {
            $task->success();
        } else {
            $task->fail();
        }
    }

    /**
     * @param $task_id
     * @return bool
     */
    public function isLocked($task_id)
    {
        return (file_exists("{$this->lock_base}{$task_id}.lock"));
    }

    /**
     * Use if you want to
     *
     * @param $task_id
     */
    public function lock($task_id)
    {
        $this->locked_tasks[] = $task_id;
        File::putContents("{$this->lock_base}{$task_id}.lock", 1);
    }

    /**
     * @param $task_id
     */
    public function unlock($task_id)
    {
        $file = "{$this->lock_base}/{$task_id}.lock";
        if (file_exists($file)) {
            unlink($file);
            unset($this->locked_tasks[$task_id]);
        }
    }

    ###############################################################################
    #                                                                             #
    #                         Start of the tasks block.                           #
    #                                                                             #
    ###############################################################################

    /**
     * Push instant queued messages, Apns, Gcm (Every minute)
     *
     * @param \Cron_Model_Cron $task
     */
    public function pushinstant($task)
    {
        # Init
        $now = \Zend_Date::now()->toString('y-MM-dd HH:mm:ss');

        # Check for Individual Push module
        if (\Push_Model_Message::hasIndividualPush()) {
            $base = \Core_Model_Directory::getBasePathTo("/app/local/modules/IndividualPush/");

            # Models
            if (is_readable("{$base}/Model/Customer/Message.php") && is_readable("{$base}/Model/Db/Table/Customer/Message.php")) {
                require_once "{$base}/Model/Customer/Message.php";
                require_once "{$base}/Model/Db/Table/Customer/Message.php";
            }
        }

        # Fetch instant message in queue.
        $messages = (new \Push_Model_Message())->findAll(
            [
                'status IN (?)' => ['queued'],
                'send_at IS NULL OR send_at <= ?' => $now,
                'send_until IS NULL OR send_until >= ?' => $now,
                'type_id = ?' => \Push_Model_Message::TYPE_PUSH
            ],
            'created_at DESC'
        );

        if (count($messages) > 0) {
            # Set all fetched messages to sending
            foreach ($messages as $message) {
                $message->updateStatus('sending');
            }

            foreach ($messages as $message) {
                echo sprintf("[CRON] Message Id: %s, Title: %s \n", $message->getId(), $message->getTitle());
                # Send push
                $message->push();
            }
        }

        // Clean-up failed push!
        $now = \Zend_Date::now()->toString('y-MM-dd HH:mm:ss');

        /**
         * @var $failedPushs \Push_Model_Message[]
         */
        $failedPushs = (new \Push_Model_Message())->findAll(
            [
                'status = ?' => 'failed',
                'DATE_ADD(updated_at, INTERVAL 3 DAY) < ?' => $now, // Messages expired three days ago!
            ]
        );

        foreach ($failedPushs as $failedPush) {
            $detail = sprintf("[%s - %s - %s]",
                $failedPush->getId(),
                $failedPush->getTitle(),
                $failedPush->getText());
            $this->log('[Push Clean]: cleaned-up ' . $detail . ' failed push.');

            $failedPush->delete();
        }

        $this->log('[Push Clean]: cleaned-up ' . $failedPushs->count() . ' failed push.');
    }

    /**
     * Cleaning-up/rotate old/unused logs (Every day at 00:05 AM)
     *
     * @param \Cron_Model_Cron $task
     */
    public function logrotate($task)
    {
        $log_files = new \DirectoryIterator("{$this->root_path}/var/log/");
        foreach ($log_files as $file) {
            $filename = $file->getFilename();
            $pathname = $file->getPathname();

            # Clean up info_* logs
            if (strpos($filename, "info_") !== false) {
                unlink($pathname);
            }

            # Clean up migration_* logs
            if (strpos($filename, "migration_") !== false) {
                unlink($pathname);
            }

            # Clean up error_* logs
            if (strpos($filename, "error_") !== false) {
                unlink($pathname);
            }

            # Clean up output_* logs
            if (strpos($filename, "output.log") !== false) {
                unlink($pathname);
            }

            # Clean up output_* logs
            if (strpos($filename, "cron-output.log") !== false) {
                unlink($pathname);
            }

            # Clean up output_* logs
            if (strpos($filename, "cron.log") !== false) {
                unlink($pathname);
            }

        }

        # This folder is not always present +4.9.1.
        if (is_readable("{$this->root_path}/var/log/modules/")) {
            $module_log_files = new \DirectoryIterator("{$this->root_path}/var/log/modules/");
            foreach ($module_log_files as $file) {
                $pathname = $file->getPathname();

                # Clean up all logs
                unlink($pathname);
            }
        }

    }

    /** NOTE: APK & Sources queues shares the same lock, as one may break the other */

    /**
     * APK Generator queue
     *
     * @param \Cron_Model_Cron $task
     */
    public function apkgenerator($task)
    {
        # We do really need to lock this thing !
        if (!$this->isLocked("generator")) {
            $this->lock("generator");

            # Generate the APK
            $queue = \Application_Model_ApkQueue::getQueue();
            foreach ($queue as $apk) {
                # Keep APK Queue id
                $apk_id = $apk->getId();

                try {
                    $this->log(sprintf("Generating App: ID[%s], Name[%s], Target[APK]", $apk->getAppId(), $apk->getName()));
                    $apk->changeStatus("building");

                    # Trying to clean-up old related processes
                    exec("pkill -9 -U $(id -u) aapt; pkill -9 -U $(id -u) java");

                    $apk->generate();

                    # +After generation**
                    exec("pkill -9 -U $(id -u) aapt; pkill -9 -U $(id -u) java");

                    # Chmod things
                    $baseApps = \Core_Model_Directory::getBasePathTo("/var/tmp/applications/ionic/android");
                    exec("chmod -R 777 '{$baseApps}/*-{$apk->getAppId()}'");

                } catch (Exception $e) {
                    $this->log($e->getMessage());
                    # Trying to fetch APK
                    $refetch_apk = new \Application_Model_ApkQueue();
                    $refetch_apk = $refetch_apk->find($apk_id);
                    if (!$refetch_apk->getId()) {
                        $task->saveLastError("APK Generation was cancelled during the build phase, unable to continue.");
                    } else {
                        $refetch_apk->changeStatus("failed");
                        $task->saveLastError($e->getMessage());
                    }
                }

            }

            # Releasing
            $this->unlock("generator");
        } else {
            $this->log("Locked task: {$task->getName()} / generator, skipping...");
        }
    }

    /**
     * @param $task
     * @throws \Exception
     * @throws \Zend_Json_Exception
     * @throws \Zend_Layout_Exception
     */
    public function sources($task)
    {
        # We do really need to lock this thing !
        if (!$this->isLocked("generator")) {
            $this->lock("generator");

            # Generate the Source ZIP
            $queue = \Application_Model_SourceQueue::getQueue();
            foreach ($queue as $source) {
                # Keep Source Queue id
                $source_id = $source->getId();

                try {
                    $this->log(sprintf("Generating App sources: ID[%s], Name[%s], Target[%s]", $source->getAppId(), $source->getName(), $source->getType()));
                    $source->changeStatus("building");
                    $source->generate($this);
                } catch (Exception $e) {
                    $this->log($e->getMessage());

                    # Trying to fetch Source
                    $refetch_source = new \Application_Model_SourceQueue();
                    $refetch_source = $refetch_source->find($source_id);
                    if (!$refetch_source->getId()) {
                        $task->saveLastError("Source Generation was cancelled during the build phase, unable to continue.");
                    } else {
                        $refetch_source->changeStatus("failed");
                        $task->saveLastError($e->getMessage());
                    }
                }

            }

            # Releasing
            $this->unlock('generator');
        } else {
            $this->log("Locked task: {$task->getName()} / generator, skipping...");
        }
    }

    /**
     * Let's Encrypt certificates renewal
     *
     * @param $task
     * @throws \Exception
     * @throws \Zend_Exception
     */
    public function letsencrypt($task)
    {
        $letsencrypt_disabled = __get('letsencrypt_disabled');
        if ($letsencrypt_disabled > time()) {
            $this->log(__("[Let's Encrypt] cron renewal is disabled until %s due to rate limit hit, skipping.",
                date('d/m/Y H:i:s', $letsencrypt_disabled)));
            return;
        }

        # Enabling again after the 7 days period
        __set("letsencrypt_disabled", 0);

        if (!$this->isLocked($task->getId())) {
            $this->lock($task->getId());

            $email = __get("support_email");
            $root = path("/");
            $base = path("/var/apps/certificates/");

            // Check panel type
            $panel_type = __get('cpanel_type');

            // Ensure folders have good rights
            exec("chmod -R 775 {$base}");
            if (is_readable("{$root}/.well-known")) {
                exec("chmod -R 775 {$root}/.well-known");
            }

            $letsencrypt_env = __get('letsencrypt_env');
            $acme = new Cert($letsencrypt_env !== 'staging');

            try {
                $acme->getAccount();
            } catch (\Exception $e) {
                $acme->register(true, $email);
            }

            try {
                $certs = (new \System_Model_SslCertificates())->findAll(
                    [
                        'source = ?' => \System_Model_SslCertificates::SOURCE_LETSENCRYPT,
                        'status = ?' => 'enabled',
                        new \Zend_Db_Expr('TIMESTAMP(now()) > TIMESTAMP(DATE_ADD(renew_date, INTERVAL 75 day))')
                    ]
                );

                foreach ($certs as $cert) {

                    try {

                        // Before generating certificate again, compare $hostnames
                        $renew = false;
                        $domains = $cert->getDomains();
                        $retainDomains = [];
                        if (is_readable($cert->getCertificate()) &&
                            !empty($domains)) {

                            $certContent = openssl_x509_parse(file_get_contents($cert->getCertificate()));

                            if (isset($certContent['extensions']) &&
                                $certContent['extensions']['subjectAltName']) {

                                // Cleanup domain names!
                                $certificateHosts = str_replace(['DNS:', ' '], '', $certContent['extensions']['subjectAltName']);
                                $certificateHosts = explode(',', $certificateHosts);
                                $dbHostname = \Siberian_Json::decode($cert->getDomains());

                                $certHostname = $cert->getHostname();

                                // Looping over to check for renew
                                foreach ($dbHostname as $hostname) {
                                    $hostname = trim($hostname);

                                    $isNotInArray = !in_array($hostname, $certificateHosts);
                                    $endWithDot = preg_match("/.*\.$/im", $hostname);
                                    if (checkdnsrr($hostname, 'CNAME')) {
                                        $r = dns_get_record($hostname, DNS_CNAME);
                                    }
                                    else { $r= false;
                                        $this->log("$hostname has no cname");
                                    }
                                    $isCname = (
                                        !empty($r) &&
                                        isset($r[0]) &&
                                        isset($r[0]['target']) &&
                                        ($r[0]['target'] === $cert->getHostname())
                                    );
                                    $isSelf = ($hostname === $cert->getHostname());

                                    // If domain is valid!
                                    if (!$endWithDot && ($isCname || $isSelf)) {
                                        $this->log(__("[Let's Encrypt] will add %s to SAN.", $hostname));

                                        $retainDomains[] = $hostname;

                                        // If domain is not in the actual certificate file, we will force the renew!
                                        if ($isNotInArray) {
                                            $renew = true;
                                        }
                                    }

                                    if ($endWithDot) {
                                        $this->log(__("[Let's Encrypt] removed domain %s, domain in dot notation is not supported.", $hostname));
                                    }
                                }
                            }

                            // Or compare expiration date (will expire in 5/30 days or less)
                            if (!$renew) {

                                $diff = $certContent['validTo_time_t'] - time();

                                //$thirty_days = 2592000;
                                $fifteenDays = 1296000;
                                //$five_days = 432000;

                                # Go with fifteen days for now.
                                if ($diff < $fifteenDays) {
                                    # Should renew
                                    $renew = true;
                                    $this->log(__("[Let's Encrypt] will expire in %s days.", floor($diff / 86400)));
                                }
                            }
                        } else {
                            $renew = true;
                        }

                        if ($renew) {

                            # Save back domains!
                            $cert
                                ->setDomains(\Siberian_Json::encode($retainDomains))
                                ->save();

                            // Clear log between hostNames!
                            try {
                                $docRoot = path('/');
                                $config = [
                                    'challenge' => 'http-01',
                                    'docroot' => $docRoot,
                                ];

                                $domainConfig = [];
                                foreach ($retainDomains as $_hostName) {
                                    $domainConfig[$_hostName] = $config;
                                }

                                $handler = function ($opts) {
                                    $fn = $opts['config']['docroot'] . $opts['key'];
                                    @mkdir(dirname($fn),0777,true);
                                    file_put_contents($fn, $opts['value']);
                                    return function ($opts) {
                                        unlink($opts['config']['docroot'] . $opts['key']);
                                    };
                                };

                                $fullChainPath = path("/var/apps/certificates/{$certHostname}/acme.fullchain.pem");
                                $certKey = $acme->generateRSAKey(2048);
                                $certKeyPath = path("/var/apps/certificates/{$certHostname}/acme.privkey.pem");
                                file_put_contents($certKeyPath, $certKey);

                                $fullChain = $acme->getCertificateChain("file://{$certKeyPath}", $domainConfig, $handler);
                                file_put_contents($fullChainPath, $fullChain);

                                // Split full-chain
                                $fullChainContent = file_get_contents($fullChainPath);
                                $parts = explode("\n\n", $fullChainContent);
                                $certPath = path("/var/apps/certificates/{$certHostname}/acme.cert.pem");
                                $chainPath = path("/var/apps/certificates/{$certHostname}/acme.chain.pem");
                                file_put_contents($certPath, $parts[0]);
                                file_put_contents($chainPath, $parts[1]);

                                $result = true;

                            } catch (\Exception $e) {
                                $result = false;
                            }

                            if ($result) {
                                // Change updated_at date, time()+10 to ensure renew is newer than updated_at
                                $cert
                                    ->setErrorCount(0)
                                    ->setRenewDate(time_to_date(time() + 10, 'YYYY-MM-dd HH:mm:ss'))
                                    ->save();

                                // Sync cPanel - Plesk - VestaCP (beta) - DirectAdmin (beta)
                                try {
                                    switch ($panel_type) {
                                        case 'plesk':
                                            (new \Siberian_Plesk())->uploadCertificate($cert);
                                            break;
                                        case 'pleskcli':
                                            (new \Siberian\PleskCli())->installCertificate($cert);
                                            break;
                                        case 'cpanel':
                                            $cpanel = new \Siberian_Cpanel();
                                            $cpanel->updateCertificate($cert);
                                            break;
                                        case 'vestacp':
                                            $vestacp = new \Siberian_VestaCP();
                                            $vestacp->updateCertificate($cert);
                                            break;
                                        case 'vestacpcli':
                                            (new VestaCPCli())->installCertificate($cert);

                                            break;
                                        case 'directadmin':
                                            $directadmin = new \Siberian_DirectAdmin();
                                            $directadmin->updateCertificate($cert);
                                            break;
                                        case 'self':
                                            $this->log('Self-managed sync is not available for now.');
                                            break;
                                    }
                                } catch (\Exception $e) {
                                    $this->log(__("[Let's Encrypt] Something went wrong with the API Sync to %s, retry or check in your panel if your SSL certificate is correctly setup.", $panel_type));
                                }

                                // SocketIO
                                if (class_exists('SocketIO_Model_SocketIO_Module') &&
                                    method_exists('SocketIO_Model_SocketIO_Module', 'killServer')) {
                                    \SocketIO_Model_SocketIO_Module::killServer();
                                }

                            } else {
                                $cert
                                    ->setErrorCount($cert->getErrorCount() + 1)
                                    ->setErrorDate(time_to_date(time(), 'YYYY-MM-dd HH:mm:ss'))
                                    ->setRenewDate(time_to_date(time() + 10, 'YYYY-MM-dd HH:mm:ss'))
                                    ->save();
                            }
                        }

                    } catch (\Exception $e) {
                        if ((strpos($e->getMessage(), 'many currently pending authorizations') !== false) ||
                            (strpos($e->getMessage(), 'many certificates already issued') !== false)) {
                            # We hit the rate limit, disable for the next seven days
                            $in_a_week = time() + 604800;
                            __set('letsencrypt_disabled', $in_a_week);
                        }

                        $cert
                            ->setErrorCount($cert->getErrorCount() + 1)
                            ->setErrorDate(time_to_date(time(), "YYYY-MM-dd HH:mm:ss"))
                            ->save();
                    }

                    # Disable the certificate after too much errors
                    if ($cert->getErrorCount() >= 3) {
                        $cert
                            ->setStatus("disabled")
                            ->save();

                        # Send a message to the Admin
                        $description = 'It seems that the renewal of the following SSL Certificate %s is failing, please check in <b>Settings > Advanced > Configuration</b> for the specified certificate.';

                        $notification = new \Backoffice_Model_Notification();
                        $notification
                            ->setTitle(__('Alert: The SSL Certificate %s automatic renewal failed.', $cert->getHostname()))
                            ->setDescription(__($description, $cert->getHostname()))
                            ->setSource('cron')
                            ->setType('alert')
                            ->setIsHighPriority(1)
                            ->setObjectType(get_class($cert))
                            ->setObjectId($cert->getId())
                            ->save();

                        \Backoffice_Model_Notification::sendEmailForNotification($notification);
                    }
                }

            } catch (Exception $e) {
                $this->log($e->getMessage());
                $task->saveLastError($e->getMessage());
            }

            // Releasing
            $this->unlock($task->getId());
        } else {
            $this->log("Locked task: {$task->getName()}, skipping...");
        }
    }

    /**
     * Analytics aggregation
     *
     * @param \Cron_Model_Cron $task
     */
    public function agregateanalytics($task)
    {
        //caluling on day after and before to be sure for results
        \Analytics_Model_Aggregate::getInstance()->run(time() - 60 * 60 * 24);
        \Analytics_Model_Aggregate::getInstance()->run(time());
        \Analytics_Model_Aggregate::getInstance()->run(time() + 60 * 60 * 24);
    }

    /**
     * @param $task
     */
    public function checkpayments($task)
    {
        // We do really need to lock this thing !
        $this->lock($task->getId());

        try {
            if (method_exists('Subscription_Model_Subscription_Application', 'checkRecurrencies')) {
                \Subscription_Model_Subscription_Application::checkRecurrencies($this);
            }

            // Update subscription statuses cache!
            if (method_exists('Subscription_Model_Subscription_Application', 'cacheStatuses')) {
                \Subscription_Model_Subscription_Application::cacheStatusesAndSync($this);
            }

        } catch (\Exception $e) {
            $this->log($e->getMessage());
            $task->saveLastError($e->getMessage());
        }

        # Releasing
        $this->unlock($task->getId());
    }

    /**
     * Check disk usage every day
     *
     * @param $task
     */
    public function diskusage($task)
    {
        # We do really need to lock this thing !
        $this->lock($task->getId());

        try {
            # Timeout to 5 minutes.
            $this->log('[Fetching current disk usage]');
            \Siberian_Cache::getDiskUsage(true);

        } catch (Exception $e) {
            $this->log($e->getMessage());
            $task->saveLastError($e->getMessage());
        }

        $this->log('[Done fetching current disk usage]');

        # Releasing
        $this->unlock($task->getId());
    }

    /**
     * Alerts watcher
     *
     * @param $task
     */
    public function alertswatcher($task)
    {
        # We do really need to lock this thing !
        $this->lock($task->getId());

        try {
            # Search for stuck builds
            $current_time = time();
            $apk_stucks = \Application_Model_ApkQueue::getStuck($current_time);
            $source_stucks = \Application_Model_SourceQueue::getStuck($current_time);

            # APK Round
            foreach ($apk_stucks as $apk_stuck) {
                $description = "You have an APK generation stuck for more than 1 hour, please check in <b>Settings > Advanced > Cron</b> for the stuck build.<br />To unlock further builds you can remove locks from the button below.";

                $notification = new \Backoffice_Model_Notification();
                $notification
                    ->setTitle(
                        __("Alert: The APK build for the Application: %s (%s) is stuck for more than one hour.",
                            $apk_stuck->getName(),
                            $apk_stuck->getAppId()))
                    ->setDescription(__($description))
                    ->setSource("cron")
                    ->setType("alert")
                    ->setIsHighPriority(1)
                    ->setObjectType(get_class($apk_stuck))
                    ->setObjectId($apk_stuck->getId())
                    ->save();

                # Also change stuck build to failed.
                $apk_stuck->changeStatus("failed");
            }

            # Sources Round
            foreach ($source_stucks as $source_stuck) {
                $description = "You have a Source generation stuck for more than 1 hour, please check in <b>Settings > Advanced > Cron</b> for the stuck build.<br />To unlock further builds you can remove locks from the button below.";

                $notification = new \Backoffice_Model_Notification();
                $notification
                    ->setTitle(
                        __("Alert: The Source build for the Application: %s (%s) is stuck for more than one hour.",
                            $source_stuck->getName(),
                            $source_stuck->getAppId()))
                    ->setDescription(__($description))
                    ->setSource("cron")
                    ->setType("alert")
                    ->setIsHighPriority(1)
                    ->setObjectType(get_class($source_stuck))
                    ->setObjectId($source_stuck->getId())
                    ->save();

                # Also change stuck build to failed.
                $source_stuck->changeStatus("failed");
            }

            # More alerts to come.

        } catch (Exception $e) {
            $this->log($e->getMessage());
            $task->saveLastError($e->getMessage());
        }

        # Releasing
        $this->unlock($task->getId());
    }



    ###############################################################################
    #                                                                             #
    #                          End of the tasks block.                            #
    #                                                                             #
    ###############################################################################


    public function __destruct()
    {
        /** Detect too long processes to alert admin */
        $exec_time = microtime(true) - $this->start;
        $this->log("Execution time {$exec_time}");
    }

}
