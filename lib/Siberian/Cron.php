<?php
/**
 * Class Siberian_Cron
 *
 * @author Xtraball SAS <dev@xtraball.com>
 *
 * @version 4.2.0
 */

class Siberian_Cron {

	/**
	 * @var Cron_Model_Cron
	 */
	protected $cron;

	/**
	 * @var Siberian_Log
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
	protected $locked_tasks = array();

	/**
	 * @var integer
	 */
	protected $start;

	public function __construct(){
		$this->cron = new Cron_Model_Cron();
		$this->logger = Zend_Registry::get("logger");
		$this->lock_base = Core_Model_Directory::getBasePathTo($this->lock_base);
		$this->start = microtime(true);
		$this->root_path = Core_Model_Directory::getBasePathTo();

		# Set the same timezone as in the Application settings.
		$timezone = System_Model_Config::getValueFor("system_timezone");
		if($timezone) {
			date_default_timezone_set($timezone);
		}
	}

	/**
	 * @param $text
	 */
	public function log($text) {
		echo sprintf("[CRON: %s]: %s\n", date("Y-m-d H:i:s"), $text);
	}

	public function triggerAll(){
		if(!Cron_Model_Cron::is_active()) {
			$this->log("Cron is disabled in your system, see: Backoffice > Settings > Advanced > Configuration > Cron");
			return;
		}

		try {
			$minute     = (int)date("i");
			$hour       = (int)date("G");
			$month_day  = (int)date("j");
			$month      = (int)date('m');
			$week_day   = (int)date('w');

			$all = $this->cron->getActiveActions($minute, $hour, $month_day, $month, $week_day);

			$actions = array();
			foreach ($all as $task){
				$actions[] = array(
					"id" => $task->getCommand(),
					"command" => $task->getCommand(),
				);
				$this->execute($task);
			}
			
			return $actions;
		} catch (Exception $e) {
			if(APPLICATION_ENV === "development") {
				Zend_Debug::dump($e);
			}
			$this->log($e->getMessage());
			return false;
		}
	}

	/**
	 * @param Cron_Model_Cron $task
	 */
	public function execute($task){
		/** Avoid duplicates when a task takes too long */
		$task->trigger();
		$success = true;

		if(!$this->isLocked($task->getId())) {
			$this->log("Executing task: ".$task->getName());

			/** Non blocking tasks */
			try {
				
				$command = $task->getCommand();
				if(strpos($command, "::") !== false) {
					# Split Class::method
					$parts = explode("::", $command);
					$class = $parts[0];
					$method = $parts[1];

					# Tests.
					if(class_exists($class) && method_exists($class, $method)) {
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

				# Unlock task in case of Exception
				$this->unlock($task->getId());

				$task->saveLastError($e->getMessage());

				$success = false;
			}
		} else {
			$this->log("Locked task: {$task->getName()}, skipping...");
		}

		if($success) {
			$task->success();
		} else {
			$task->fail();
		}
	}

	/**
	 * @param $task_id
	 * @return bool
	 */
	public function isLocked($task_id) {
		return (file_exists("{$this->lock_base}/{$task_id}.lock"));
	}

	/**
	 * Use if you want to
	 *
	 * @param $task_id
	 */
	public function lock($task_id) {
		$this->locked_tasks[] = $task_id;
		file_put_contents("{$this->lock_base}/{$task_id}.lock", 1);
	}

	/**
	 * @param $task_id
	 */
	public function unlock($task_id) {
		$file = "{$this->lock_base}/{$task_id}.lock";
		if(file_exists($file)) {
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
	 * @param Cron_Model_Cron $task
	 */
	public function pushinstant($task) {
		# Init
		$now = Zend_Date::now()->toString('y-MM-dd HH:mm:ss');

        # Check for Individual Push module
        if(Push_Model_Message::hasIndividualPush()) {
            $base = Core_Model_Directory::getBasePathTo("/app/local/modules/IndividualPush/");

            # Models
			if(is_readable("{$base}/Model/Customer/Message.php") && is_readable("{$base}/Model/Db/Table/Customer/Message.php")) {
				require_once "{$base}/Model/Customer/Message.php";
				require_once "{$base}/Model/Db/Table/Customer/Message.php";
			}
        }



		# Fetch instant message in queue.
		$message = new Push_Model_Message();
		$messages = $message->findAll(
			array(
				'status IN (?)' => array('queued'),
				'send_at IS NULL OR send_at <= ?' => $now,
				'send_until IS NULL OR send_until >= ?' => $now,
				'type_id = ?' => Push_Model_Message::TYPE_PUSH
			),
			'created_at DESC'
		);

		if(count($messages) > 0) {
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
	}

	/**
	 * Cleaning-up/rotate old/unused logs (Every day at 00:05 AM)
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function logrotate($task) {
		$log_files = new DirectoryIterator("{$this->root_path}/var/log/");
		foreach($log_files as $file) {
			$filename = $file->getFilename();
			$pathname = $file->getPathname();

			# Clean up info_* logs
			if(strpos($filename, "info_") !== false) {
				unlink($pathname);
			}

			# Clean up migration_* logs
			if(strpos($filename, "migration_") !== false) {
				unlink($pathname);
			}

			# Clean up error_* logs
			if(strpos($filename, "error_") !== false) {
				unlink($pathname);
			}

			# Clean up output_* logs
			if(strpos($filename, "output.log") !== false) {
				unlink($pathname);
			}

			# Clean up output_* logs
			if(strpos($filename, "cron-output.log") !== false) {
				unlink($pathname);
			}

			# Clean up output_* logs
			if(strpos($filename, "cron.log") !== false) {
				unlink($pathname);
			}

		}
	}

	/** NOTE: APK & Sources queues shares the same lock, as one may break the other */

	/**
	 * APK Generator queue
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function apkgenerator($task) {
		# We do really need to lock this thing !
		if(!$this->isLocked("generator")) {
			$this->lock("generator");

			# Generate the APK
			$queue = Application_Model_ApkQueue::getQueue();
			foreach($queue as $apk) {
				try {
					$this->log(sprintf("Generating App: ID[%s], Name[%s], Target[APK]", $apk->getAppId(), $apk->getName()));
					$apk->changeStatus("building");
					$apk->generate();
				} catch(Exception $e) {
					$this->log($e->getMessage());
					$apk->changeStatus("failed");
					$task->saveLastError($e->getMessage());
				}

			}

			# Releasing
			$this->unlock("generator");
		} else {
			$this->log("Locked task: {$task->getName()} / generator, skipping...");
		}
	}

	/**
	 * Sources Generator queue
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function sources($task) {
		# We do really need to lock this thing !
		if(!$this->isLocked("generator")) {
			$this->lock("generator");

			# Generate the Source ZIP
			$queue = Application_Model_SourceQueue::getQueue();
			foreach($queue as $source) {
				try {
					$this->log(sprintf("Generating App sources: ID[%s], Name[%s], Target[%s]", $source->getAppId(), $source->getName(), $source->getType()));
					$source->changeStatus("building");
					$source->generate();
				} catch(Exception $e) {
					$this->log($e->getMessage());
					$source->changeStatus("failed");
					$task->saveLastError($e->getMessage());
				}

			}

			# Releasing
			$this->unlock("generator");
		} else {
			$this->log("Locked task: {$task->getName()} / generator, skipping...");
		}
	}

    /**
     * Let's Encrypt certificates renewal
     *
     * @param $task
     */
    public function letsencrypt($task) {
        if(!$this->isLocked($task->getId())) {
            $this->lock($task->getId());

            $email = System_Model_Config::getValueFor("support_email");
            $root = Core_Model_Directory::getBasePathTo("/");
            $base = Core_Model_Directory::getBasePathTo("/var/apps/certificates/");

            // Check panel type
            $panel_type = System_Model_Config::getValueFor("cpanel_type");

            // Ensure folders have good rights
            exec("chmod -R 775 {$base}");
            if(is_readable("{$root}/.well-known")) {
                exec("chmod -R 775 {$root}/.well-known");
            }

            $lets_encrypt = new Siberian_LetsEncrypt($base, $root, false);

            // Use staging environment
            $letsencrypt_env = System_Model_Config::getValueFor("letsencrypt_env");
            if($letsencrypt_env == "staging") {
                $lets_encrypt->setIsStaging();
            }

            if(!empty($email)) {
                $lets_encrypt->contact = array("mailto:{$email}");
            }

            try {
                $lets_encrypt->initAccount();

                $ssl_certificates = new System_Model_SslCertificates();

                $to_renew = new Zend_Db_Expr("renew_date < updated_at");
                $certs = $ssl_certificates->findAll(array(
                    "source = ?" => System_Model_SslCertificates::SOURCE_LETSENCRYPT,
                    $to_renew
                ));

                foreach($certs as $cert) {

                    try {

                        // Before generating certificate again, compare $hostnames
                        $renew = false;
                        $domains = $cert->getDomains();
                        if(is_readable($cert->getCertificate()) && !empty($domains)) {
                            $cert_content = openssl_x509_parse(file_get_contents($cert->getCertificate()));
                            if(isset($cert_content["extensions"]) && $cert_content["extensions"]["subjectAltName"]) {
                                $certificate_hosts = explode(",", str_replace("DNS:", "", $cert_content["extensions"]["subjectAltName"]));
                                $hostnames = Siberian_Json::decode($cert->getDomains());
                                foreach($hostnames as $hostname) {
                                    $hostname = trim($hostname);
                                    if(!in_array($hostname, $certificate_hosts)) {
                                        $renew = true;
                                        $this->log(__("[Let's Encrypt] will add %s to SAN.", $hostname));
                                    }
                                }
                            }
                        } else {
                            $renew = true;
                        }

                        if($renew) {
                            // Clear log between hostnames.
                            $lets_encrypt->clearLog();
                            $result = $lets_encrypt->signDomains(Siberian_Json::decode($cert->getDomains()));
                        } else {
                            $result = true;
                        }

                        if($result) {
                            // Change updated_at date, time()+10 to ensure renew is newer than updated_at
                            $cert
                                ->setRenewDate(time_to_date(time()+10, "YYYY-MM-dd HH:mm:ss"))
                                ->save();

                            // Sync cPanel - Plesk - VestaCP (beta) - DirectAdmin (beta)
                            try {
                                switch($panel_type) {
                                    case "plesk":
                                            $siberian_plesk = new Siberian_Plesk();
                                            $siberian_plesk->updateCertificate($cert);
                                        break;
                                    case "cpanel":
                                            $cpanel = new Siberian_Cpanel();
                                            $cpanel->updateCertificate($cert);
                                        break;
                                    case "vestacp":
                                            $vestacp = new Siberian_VestaCP();
                                            $vestacp->updateCertificate($cert);
                                        break;
                                    case "directadmin":
                                            $directadmin = new Siberian_DirectAdmin();
                                            $directadmin->updateCertificate($cert);
                                        break;
                                    case "self":
                                            $this->log("Self-managed sync is not available for now.");
                                        break;
                                }
                            } catch(Exception $e) {
                                $this->log(__("[Let's Encrypt] Something went wrong with the API Sync to %s, retry or check in your panel if your SSL certificate is correctly setup.", $panel_type));
                            }

                            // SocketIO
                            if(class_exists("SocketIO_Model_SocketIO_Module")) {
                                SocketIO_Model_SocketIO_Module::killServer();
                            }

                        } else {
                            $cert
                                ->setErrorDate(time_to_date(time(), "YYYY-MM-dd HH:mm:ss"))
                                ->setErrorLog($lets_encrypt->getLog())
                                ->save();
                        }
                    } catch(Exception $e) {
                        $cert
                            ->setErrorDate(time_to_date(time(), "YYYY-MM-dd HH:mm:ss"))
                            ->setErrorLog($lets_encrypt->getLog())
                            ->save();
                    }
                }

            } catch(Exception $e) {
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
     * @param $task
     */
    public function statistics($task) {
        if(System_Model_Config::getValueFor("send_statistics") != "1") {
            $this->log("Statistics are disabled.");
            return;
        }

        $this->lock($task->getId());

        try {
            $db = Zend_Db_Table::getDefaultAdapter();

            $editor_user_count = $db->fetchRow($db->select()->from("admin", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            $backoffice_user_count = $db->fetchRow($db->select()->from("backoffice_user", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            $apps_app_count = $db->fetchRow($db->select()->from("application", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            $apps_angular_count = $db->fetchRow($db->select()->from("application", array(new Zend_Db_Expr("COUNT(*) AS total")))->where("design_code = ?", "angular"));
            $apps_ionic_count = $db->fetchRow($db->select()->from("application", array(new Zend_Db_Expr("COUNT(*) AS total")))->where("design_code = ?", "ionic"));
            $apps_domain_count = $db->fetchRow($db->select()->from("application", array(new Zend_Db_Expr("COUNT(*) AS total")))->where("domain IS NOT NULL"));
            if(Siberian_Version::is("PE")) {
                $whitelabel_count = $db->fetchRow($db->select()->from("whitelabel_editor", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            } else {
                $whitelabel_count = 0;
            }
            $push_message_count = $db->fetchRow($db->select()->from("push_messages", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            $android_device_count = $db->fetchRow($db->select()->from("push_gcm_devices", array(new Zend_Db_Expr("COUNT(*) AS total"))));
            $ios_device_count = $db->fetchRow($db->select()->from("push_apns_devices", array(new Zend_Db_Expr("COUNT(*) AS total"))));

            $modules_model = new Installer_Model_Installer_Module();
            $all_modules = $modules_model->findAll();
            $modules = array();
            foreach($all_modules as $module) {
                $modules[] = array(
                    "name" => $module->getData("name"),
                    "version" => $module->getVersion(),
                );
            }

            $statistics = array(
                "secret" => Core_Model_Secret::SECRET,
                "data" => array(
                    "siberian" => array(
                        "version" => Siberian_Version::VERSION,
                        "design" => design_code(),
                        "use_https" => System_Model_Config::getValueFor("use_https"),
                        "disable_cron" => System_Model_Config::getValueFor("disable_cron"),
                        "environment" => System_Model_Config::getValueFor("environment"),
                        "update_channel" => System_Model_Config::getValueFor("update_channel"),
                        "cpanel_type" => System_Model_Config::getValueFor("cpanel_type"),
                        "modules" => $modules,
                    ),
                    "editor" => array(
                        "user_count" => $editor_user_count["total"],
                        "whitelabel_count" => $whitelabel_count["total"],
                    ),
                    "backoffice" => array(
                        "user_count" => $backoffice_user_count["total"],
                    ),
                    "apps" => array(
                        "app_count" => $apps_app_count["total"],
                        "angular_count" => $apps_angular_count["total"],
                        "ionic_count" => $apps_ionic_count["total"],
                        "with_domain" => $apps_domain_count["total"],
                    ),
                    "push" => array(
                        "message_count" => $push_message_count["total"],
                        "android_devices" => $android_device_count["total"],
                        "ios_devices" => $ios_device_count["total"],
                    ),
                ),
            );

            $request = new Siberian_Request();
            $request->post(sprintf("http://stats.xtraball.com/?type=%s", Siberian_Version::TYPE), $statistics);

        } catch(Exception $e){
            $this->log($e->getMessage());
            $task->saveLastError($e->getMessage());
        }

        # Disable when done.
        $task->disable();
        # Releasing
        $this->unlock($task->getId());
    }

	/**
	 * Analytics aggregation
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function agregateanalytics($task) {
		//caluling on day after and before to be sure for results
		Analytics_Model_Aggregate::getInstance()->run(time() - 60 * 60 * 24);
		Analytics_Model_Aggregate::getInstance()->run(time());
		Analytics_Model_Aggregate::getInstance()->run(time() + 60 * 60 * 24);
	}

	/**
	 * Install the Android tools (once)
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function androidtools($task) {
		# We do really need to lock this thing !
		$this->lock($task->getId());

		try {
			$script = "{$this->root_path}/var/apps/ionic/tools/sdk-updater.php";

			require_once $script;
		} catch(Exception $e){
			$this->log($e->getMessage());
			$task->saveLastError($e->getMessage());
		}

		# Disable when done.
		$task->disable();
		# Releasing
		$this->unlock($task->getId());
	}

	/**
	 * Rebuilds the cache
	 *
	 * @param Cron_Model_Cron $task
	 */
	public function cachebuilder($task) {
		# We do really need to lock this thing !
		$this->lock($task->getId());

		try {
			Siberian_Cache_Design::clearCache();
			# Disable when success.
			$task->disable();
		} catch(Exception $e){
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


	public function __destruct() {
		/** Detect too long processes to alert admin */
		$exec_time = microtime(true) - $this->start;
		$this->log("Execution time {$exec_time}");
	}

}
