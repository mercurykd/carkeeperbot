<?php

class Bot
{
    public $api;
    public $admin;
    public $admins;
    public $input;
    public $callback;
    public $ip;
    public $db = '/configs/foldersync.db';

    public function __construct()
    {
        $this->ip  = $this->getIP();
        $this->api = "https://api.telegram.org/bot{$this->getKey()}/";
        $this->setcommands([
            'commands' => [
                [
                    'command'     => 'id',
                    'description' => 'your id telegram',
                ],
            ]
        ]);
    }

    public function getKey()
    {
        return trim(file_get_contents('/configs/key'));
    }

    public function getAdmins()
    {
        $t = trim(file_get_contents('/configs/admins'));
        if (empty($t)) {
            return [];
        }
        foreach (explode("\n", $t) as $v) {
            [$i, $j] = explode(':', $v);
            $a[$i] = $j;
        }
        return $a;
    }

    public function setAdmins($id)
    {
        $a = $this->getAdmins();
        $a[$id] = $this->getInfoUser($id)['result']['user']['first_name'];
        file_put_contents('/configs/admins', implode("\n", array_map(fn ($k, $v) => "$k:$v", array_keys($a), array_values($a))));
        $c = array_merge($this->getcommands(), [
            [
                'command'     => 'menu',
                'description' => '...',
            ],
        ]);
        foreach ($a as $k => $v) {
            $this->setcommands([
                'scope'    => [
                    'type'    => 'chat',
                    'chat_id' => $k,
                ],
                'commands' => $c,
            ]);
        }
        $this->admins = $a;
    }

    public function delAdmin($id)
    {
        $a = $this->getAdmins();
        unset($a[$id]);
        file_put_contents('/configs/admins', implode("\n", array_map(fn ($k, $v) => "$k:$v", array_keys($a), array_values($a))));
        $this->delcommands([
            'scope' => [
                'type'    => 'chat',
                'chat_id' => $id,
            ],
        ]);
        $this->admins = $a;
        $this->admins();
    }

    public function cron()
    {
        $path = '/var/tmp/';
        while (true) {
            $f = scandir($path);
            foreach ($f as $k => $v) {
                if (preg_match('~^\.|tacitpart~', $v)) {
                    unset($f[$k]);
                }
            }
            if ($f) {
                foreach ($f as $k => $v) {
                    $this->mailing("$path$v");
                }
            } else {
                sleep(5);
            }
        }
    }

    public function mailing($path)
    {
        foreach ($this->getAdmins() as $id => $name) {
            switch (true) {
                case preg_match('~\.ts$~', $path):
                    $this->split($path);
                    break;
                case preg_match('~\.jpg$~', $path):
                    $this->sendPhoto($id, curl_file_create($path));
                    break;
                case preg_match('~\.mp4$~', $path):
                    $this->sendVideo($id, curl_file_create($path));
                    break;

                default:
                    $this->sendFile($id, curl_file_create($path));
                    break;
            }
            unlink($path);
        }
    }

    public function split($path)
    {
        exec("ffmpeg -i $path -crf 25 -preset medium -movflags +faststart -c:a copy -map 0 -segment_time 00:00:30 -f segment -reset_timestamps 1 " . str_replace('.ts', '', $path) . "%03d.mp4");
        unlink($path);
    }

    public function polling()
    {
        $this->notify("старт бота <code>{$this->ip}</code>");
        while (true) {
            $r = $this->request('getUpdates', [
                'offset'  => $offset ?? -1,
                'limit'   => 3,
                'timeout' => 5, // long polling 10 sec
            ]);
            if (!empty($r['description'])) {
                $this->notify($r['description']);
                die($r['description']);
            }
            if (!empty($r['result'])) {
                foreach ($r['result'] as $v) {
                    try {
                        $this->input($v);
                        $this->callbackCheck();
                    } catch (\Throwable $e) {
                        var_dump('error:', $e);
                    }
                    $offset = max($offset, $v['update_id']) + 1;
                }
            }
        }
    }

    public function input($input)
    {
        $this->callback = false;
        $this->input    = [
            'bot'               => $input['message']['from']['is_bot'] ?? false,
            'pinned'            => $input['message']['pinned_message'] ?? false,
            'message'           => $input['callback_query']['message']['text'] ?? $input['message']['text'] ?? $input['channel_post']['text'] ?? $input['message']['caption'] ?? '',
            'message_id'        => $input['callback_query']['message']['message_id'] ?? $input['message']['message_id'] ?? $input['channel_post']['message_id'],
            'chat'              => $input['message']['chat']['id'] ?? $input['callback_query']['message']['chat']['id'] ?? $input['channel_post']['chat']['id'] ?? $input['my_chat_member']['chat']['id'],
            'from'              => $input['message']['from']['id'] ?? $input['inline_query']['from']['id'] ?? $input['callback_query']['from']['id'] ?? $input['channel_post']['chat']['id'] ?? $input['my_chat_member']['from']['id'] ?? $input['pre_checkout_query']['from']['id'],
            'username'          => $input['message']['from']['first_name'] ?? $input['inline_query']['from']['first_name'] ?? $input['callback_query']['from']['first_name'],
            'forum'             => $input['message']['message_thread_id'] ?? '',
            'query'             => $input['inline_query']['query'] ?? '',
            'inlid'             => $input['inline_query']['id'] ?? '',
            'group'             => in_array($input['message']['chat']['type'], ['group', 'supergroup']),
            'sticker_id'        => $input['message']['sticker']['file_id'] ?? false,
            'channel'           => !empty($input['channel_post']['message_id']),
            'callback'          => $input['callback_query']['data'] ?? false,
            'callback_id'       => $input['callback_query']['id'] ?? false,
            'pre_checkout_id'   => $input['pre_checkout_query']['id'] ?? false,
            'invoice_payload'   => $input['pre_checkout_query']['invoice_payload'] ?? false,
            'payment_payload'   => $input['message']['successful_payment']['invoice_payload'] ?? false,
            'payment_amount'    => $input['message']['successful_payment']['total_amount'] ?? false,
            'photo'             => $input['message']['photo'] ?? false,
            'file_name'         => $input['message']['document']['file_name'] ?? false,
            'file_id'           => $input['message']['document']['file_id'] ?? $input['message']['photo'][0]['file_id'] ?? false,
            'caption'           => $input['message']['caption'] ?? false,
            'reply'             => $input['message']['reply_to_message']['message_id'] ?? false,
            'reply_from'        => $input['message']['reply_to_message']['from']['id'] ?? $input['callback_query']['message']['reply_to_message']['from']['id'] ?? false,
            'reply_text'        => $input['message']['reply_to_message']['text'] ?? false,
            'new_member_id'     => $input['my_chat_member']['new_chat_member']['user']['id'] ?? false,
            'new_member_status' => $input['my_chat_member']['new_chat_member']['status'] ?? false,
            'entities'          => $input['message']['entities'] ?? $input['message']['caption_entities'] ?? false,
        ];
        $this->admins = $this->getAdmins();
        if (empty($this->admins)) {
            $this->setAdmins($this->input['from']);
        }
        $this->input['admin'] = array_key_exists($this->input['from'], $this->admins);
        $this->session();
        $this->route();
    }

    public function session()
    {
        session_id($this->input['from']);
        session_start();
        if (!empty($_SESSION['reply'])) {
            if (empty($this->input['reply'])) {
                foreach ($_SESSION['reply'] as $k => $v) {
                    $this->delete($this->input['chat'], $k);
                }
                unset($_SESSION['reply']);
            }
        }
    }

    public function route()
    {
        switch (true) {
            case !empty($this->input['reply']) && !empty($_SESSION['reply'][$this->input['reply']]):
                $this->reply();
                break;
            case !empty($this->input['pinned'])
            || !empty($this->input['bot'])
            || empty($this->input['message_id']):
                break;
            case preg_match('~^/(?P<method>[^\s]+)(?:\s(?P<args>.+))?$~', $this->input['callback'] ?: $this->input['message'], $m):
                if (method_exists($this, $m['method'])) {
                    $users_methods = [
                        'id',
                    ];
                    if (!$this->input['admin'] && !in_array($m['method'], $users_methods)) {
                        return;
                    } elseif (!empty($this->input['group']) && !$this->input['admin']) {
                        return;
                    } else {
                        if (isset($m['args'])) {
                            $this->{$m['method']}(...explode('_', $m['args']));
                        } else {
                            $this->{$m['method']}();
                        }
                    }
                }
                break;
        }
    }

    public function id()
    {
        $this->send($this->input['from'], "<code>{$this->input['from']}</code>", $this->input['message_id']);
    }

    public function start()
    {
        $this->menu();
    }

    public function menu()
    {
        $data = [
            [
                [
                    'text'          => "скачать приложение",
                    'callback_data' => "/apk",
                ],
            ],
            [
                [
                    'text'          => "скачать настройки",
                    'callback_data' => "/db",
                ],
            ],
            [
                [
                    'text'          => "админы",
                    'callback_data' => "/admins",
                ],
            ],
            [
                [
                    'text'          => "пути к папкам",
                    'callback_data' => "/dirs",
                ],
            ],
        ];
        $this->uors(data: $data);
    }

    public function admins()
    {
        $text[] = "новые файлы будут рассылаться всем админам";
        $data[] = [
                [
                    'text'          => "добавить админа",
                    'callback_data' => "/sendReply введите айди_addAdmin",
                ],
        ];
        foreach ($this->admins as $id => $name) {
            $data[] = [
                [
                    'text'          => "удалить $name ($id)",
                    'callback_data' => "/delAdmin $id",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => "назад",
                'callback_data' => "/menu",
            ],
        ];
        $this->uors($text, $data);
    }

    public function getDirs()
    {
        $t = trim(file_get_contents('/configs/dirs'));
        if (empty($t)) {
            return [];
        }

        return array_filter(array_map(fn($el) => trim($el), explode("\n", $t)));
    }

    public function dirs()
    {
        $text[] = <<<TEXT
                каркипер - <code>/storage/emulated/0/carkeeperserver/temp</code>
                фотки рега - <code>/storage/Tfcard/DCIM/Picture</code>
                сохраненные видео - <code>/storage/Tfcard/DCIM/LockVideo</code>
                настройки agama launcher - <code>/storage/emulated/0/Agama</code>
                TEXT;

        $data[] = [
                [
                    'text'          => "добавить путь к папке",
                    'callback_data' => "/sendReply введите путь_addDir",
                ],
        ];
        foreach ($this->getDirs() as $k => $v) {
            $data[] = [
                [
                    'text'          => "удалить $v",
                    'callback_data' => "/delDir $k",
                ],
            ];
        }
        $data[] = [
            [
                'text'          => "назад",
                'callback_data' => "/menu",
            ],
        ];
        $this->uors($text, $data);
    }

    public function addDir($v)
    {
        $d   = $this->getDirs();
        $d[] = $v;
        file_put_contents('/configs/dirs', implode("\n", $d));
        $this->dirs();
    }

    public function delDir($k)
    {
        $d = $this->getDirs();
        unset($d[$k]);
        file_put_contents('/configs/dirs', implode("\n", $d));
        $this->dirs();
    }

    public function addAdmin($id)
    {
        $this->setAdmins($id);
        $this->admins();
    }

    public function apk()
    {
        $this->sendFile($this->input['from'], curl_file_create('/configs/FolderSync+v3.5.1.apk'), to: $this->input['message_id']);
        $this->answer($this->input['callback_id']);
    }

    public function getIP()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => 'https://ipinfo.io/ip',
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    public function db()
    {
        $port = getenv('PORT');
        if (empty($this->ip)) {
            $this->send($this->input['from'], 'не смог определить айпи сервера', $this->input['message_id']);
            return;
        }
        $this->sql("CREATE TABLE android_metadata (locale TEXT)", view: 'count');
        $this->sql("INSERT INTO android_metadata VALUES('ru_RU')", view: 'count');
        $this->sql("CREATE TABLE `accounts` (`accessKey` VARCHAR , `accessSecret` VARCHAR , `accountType` VARCHAR NOT NULL , `activeMode` SMALLINT , `allowSelfSigned` SMALLINT , `anonymous` SMALLINT , `authType` VARCHAR , `charset` VARCHAR , `consumerKey` VARCHAR , `consumerSecret` VARCHAR , `convertGoogleDocsFiles` SMALLINT , `createdDate` VARCHAR , `disableCompression` SMALLINT , `domain` VARCHAR , `groupName` VARCHAR , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `importKey` VARCHAR , `initialFolder` VARCHAR , `insecureCiphers` SMALLINT , `isLegacy` SMALLINT , `keyFilePassword` VARCHAR , `keyFileUrl` VARCHAR , `loginName` VARCHAR , `loginValidated` SMALLINT , `name` VARCHAR NOT NULL , `password` VARCHAR , `port` INTEGER , `protocol` VARCHAR , `publicKeyUrl` VARCHAR , `region` VARCHAR , `serverAddress` VARCHAR , `sortIndex` INTEGER , `sslThumbprint` VARCHAR , `useExpectContinue` SMALLINT , `useServerSideEncryption` SMALLINT )", view: 'count');
        $this->sql("CREATE TABLE `account_property` (`account_id` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `key` VARCHAR , `value` VARCHAR )", view: 'count');
        $this->sql("CREATE TABLE `folderpairs` (`account_id` INTEGER , `active` SMALLINT , `advancedSyncDefinition` BLOB , `allowedNetworks` VARCHAR , `backupSchemePattern` VARCHAR , `backupSchemeSortBasedOnFileTime` SMALLINT , `batteryThreshold` INTEGER , `cleanEmptyFolders` SMALLINT , `createDeviceFolderIfMissing` SMALLINT , `createdDate` VARCHAR , `currentStatus` VARCHAR , `deleteEmptyFolders` SMALLINT , `deleteFilesAfterSync` SMALLINT , `checkFileSizes` SMALLINT , `disallowedNetworks` VARCHAR , `excludeSyncAll` SMALLINT , `fileMasks` VARCHAR , `groupName` VARCHAR , `hasPendingChanges` SMALLINT , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `ignoreEmptyFolders` SMALLINT , `ignoreNetworkState` SMALLINT , `importKey` VARCHAR , `instantSync` SMALLINT , `lastRun` VARCHAR , `name` VARCHAR NOT NULL , `notificationEmail` VARCHAR , `notifyOnChanges` SMALLINT , `notifyOnError` SMALLINT , `notifyOnSuccess` SMALLINT , `notifyOnSync` SMALLINT , `onlySyncChanged` SMALLINT , `onlySyncWhileCharging` SMALLINT , `preserveTargetFolder` SMALLINT , `remoteFolder` VARCHAR NOT NULL , `remoteFolderReadable` VARCHAR , `rescanMediaLibrary` SMALLINT , `retrySyncOnFail` SMALLINT , `sdFolder` VARCHAR NOT NULL , `sdFolderReadable` VARCHAR , `sortIndex` INTEGER , `syncAsHotspot` SMALLINT , `syncHiddenFiles` SMALLINT , `syncInterval` VARCHAR , `syncRuleConflict` VARCHAR , `syncRuleReplaceFile` VARCHAR , `syncSubFolders` SMALLINT , `syncType` VARCHAR , `turnOnWifi` SMALLINT , `use2G` SMALLINT , `use3G` SMALLINT , `useBackupScheme` SMALLINT , `useEthernet` SMALLINT , `useMd5Checksum` SMALLINT , `useMultiThreadedSync` SMALLINT , `useOtherInternet` SMALLINT , `useRecycleBin` SMALLINT , `useRoaming` SMALLINT , `useTempFiles` SMALLINT , `useWifi` SMALLINT , `warningThresholdHours` INTEGER )", view: 'count');
        $this->sql("CREATE TABLE `synclogs` (`actions` TEXT , `createdDate` VARCHAR NOT NULL , `dataTransferred` BIGINT , `endSyncTime` VARCHAR , `errors` TEXT , `filesChecked` INTEGER , `filesDeleted` INTEGER , `filesSynced` INTEGER , `folderPair_id` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `message` VARCHAR , `status` VARCHAR )", view: 'count');
        $this->sql("CREATE TABLE `synclogchilds` (`id` INTEGER PRIMARY KEY AUTOINCREMENT , `logType` VARCHAR , `syncLog` INTEGER NOT NULL , `text` TEXT )", view: 'count');
        $this->sql("CREATE TABLE `syncrules` (`createdDate` VARCHAR NOT NULL , `folderPair_id` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `includeRule` SMALLINT NOT NULL , `longValue` BIGINT , `stringValue` VARCHAR , `syncRule` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `syncedfiles` (`folderPair_id` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `isFolder` SMALLINT , `localPath` VARCHAR NOT NULL , `md5Checksum` VARCHAR , `modifiedTime` BIGINT , `remoteChecksum` VARCHAR , `remoteModifiedTime` BIGINT , `remotePath` VARCHAR )", view: 'count');
        $this->sql("CREATE TABLE `favorites` (`account_id` INTEGER , `displayPath` VARCHAR NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `name` VARCHAR NOT NULL , `parentId` INTEGER , `pathId` VARCHAR NOT NULL , `visible` SMALLINT NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `webhooks` (`bodyType` VARCHAR NOT NULL , `folderpair_id` INTEGER NOT NULL , `httpMethod` VARCHAR NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `lastRun` VARCHAR , `lastRunResponseCode` VARCHAR , `name` VARCHAR NOT NULL , `triggerStatus` VARCHAR NOT NULL , `webHookUrl` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `webhookproperties` (`id` INTEGER PRIMARY KEY AUTOINCREMENT , `propName` VARCHAR NOT NULL , `propValue` VARCHAR NOT NULL , `webhook_id` INTEGER NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_folderpairs` (`createdDate` VARCHAR NOT NULL , `groupName` VARCHAR , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `importKey` VARCHAR , `isEnabled` SMALLINT NOT NULL , `isExcludedFromSyncAll` SMALLINT DEFAULT 0 NOT NULL , `leftAccountId` INTEGER NOT NULL , `leftFolderDisplayPath` VARCHAR NOT NULL , `leftFolderId` VARCHAR NOT NULL , `name` VARCHAR NOT NULL , `rightAccountId` INTEGER NOT NULL , `rightFolderDisplayPath` VARCHAR NOT NULL , `rightFolderId` VARCHAR NOT NULL , `sortIndex` INTEGER NOT NULL , `syncConflictRule` VARCHAR NOT NULL , `syncCreateDeviceFolderIfMissing` SMALLINT DEFAULT 0 NOT NULL , `syncDefaultScheduleId` INTEGER , `syncDeletionEnabled` SMALLINT NOT NULL , `syncDirection` VARCHAR NOT NULL , `syncDisableChecksumCalculation` SMALLINT DEFAULT 0 NOT NULL , `syncDoNotCreateEmptyFolders` SMALLINT DEFAULT 0 NOT NULL , `syncHasPendingChanges` SMALLINT NOT NULL , `syncLastRun` VARCHAR , `syncModeBackup` SMALLINT DEFAULT 0 NOT NULL , `syncModeBackupPattern` VARCHAR , `syncModeChangedFilesOnly` SMALLINT DEFAULT 0 NOT NULL , `syncModeMoveFiles` SMALLINT DEFAULT 0 NOT NULL , `syncReplaceFileRule` VARCHAR NOT NULL , `syncStatus` VARCHAR NOT NULL , `syncUseRecycleBin` SMALLINT NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_folderpair_filters` (`createdDate` VARCHAR NOT NULL , `folderPairId` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `includeRule` SMALLINT NOT NULL , `longValue` BIGINT , `stringValue` VARCHAR , `syncRule` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_folderpair_schedules` (`allowRoaming` SMALLINT NOT NULL , `allowedNetworkNames` VARCHAR , `cronString` VARCHAR NOT NULL , `disallowedNetworkNames` VARCHAR , `folderPairId` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `name` VARCHAR NOT NULL , `notificationOnChanges` SMALLINT NOT NULL , `notificationOnError` SMALLINT NOT NULL , `notificationOnSuccess` SMALLINT NOT NULL , `requireCharging` SMALLINT NOT NULL , `requireVpn` SMALLINT NOT NULL , `useAnyConnection` SMALLINT NOT NULL , `useEthernetConnection` SMALLINT NOT NULL , `useMobileConnection` SMALLINT NOT NULL , `useWifiConnection` SMALLINT NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_folderpair_synced_files` (`folderPairId` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `itemKey` VARCHAR NOT NULL , `leftChecksumMd5` VARCHAR , `leftChecksumSha1` VARCHAR , `leftModifiedTime` BIGINT NOT NULL , `leftSize` BIGINT , `rightChecksumMd5` VARCHAR , `rightChecksumSha1` VARCHAR , `rightModifiedTime` BIGINT NOT NULL , `rightSize` BIGINT , UNIQUE (`folderPairId`,`itemKey`) )", view: 'count');
        $this->sql("CREATE TABLE `v2_sync_logs` (`createdDate` VARCHAR NOT NULL , `endSyncTime` VARCHAR , `errors` TEXT , `filesChecked` INTEGER NOT NULL , `folderPairId` INTEGER NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `status` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_sync_log_items` (`dataTransferTimeMs` BIGINT NOT NULL , `dataTransferred` BIGINT NOT NULL , `errorMessage` VARCHAR , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `itemKey` VARCHAR NOT NULL , `logType` VARCHAR NOT NULL , `syncLogId` INTEGER NOT NULL , `syncSource` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_webhooks` (`bodyType` VARCHAR NOT NULL , `folderPairId` INTEGER NOT NULL , `httpMethod` VARCHAR NOT NULL , `id` INTEGER PRIMARY KEY AUTOINCREMENT , `lastRun` VARCHAR , `lastRunResponseCode` VARCHAR , `name` VARCHAR NOT NULL , `triggerStatus` VARCHAR NOT NULL , `webHookUrl` VARCHAR NOT NULL )", view: 'count');
        $this->sql("CREATE TABLE `v2_webhook_properties` (`id` INTEGER PRIMARY KEY AUTOINCREMENT , `propName` VARCHAR NOT NULL , `propValue` VARCHAR NOT NULL , `webhookId` INTEGER NOT NULL )", view: 'count');
        $this->sql("CREATE INDEX `account_property_account_id_idx` ON `account_property` ( `account_id` )", view: 'count');
        $this->sql("CREATE INDEX `synclogs_createdDate_idx` ON `synclogs` ( `createdDate` )", view: 'count');
        $this->sql("CREATE INDEX `synclogchilds_syncLog_idx` ON `synclogchilds` ( `syncLog` )", view: 'count');
        $this->sql("CREATE INDEX `syncedfiles_folderPair_id_idx` ON `syncedfiles` ( `folderPair_id` )", view: 'count');
        $this->sql("CREATE INDEX `webhookproperties_webhook_id_idx` ON `webhookproperties` ( `webhook_id` )", view: 'count');
        $this->sql("CREATE INDEX `v2_folderpairs_sortIndex_idx` ON `v2_folderpairs` ( `sortIndex` )", view: 'count');
        $this->sql("CREATE INDEX `v2_folderpairs_leftAccountId_idx` ON `v2_folderpairs` ( `leftAccountId` )", view: 'count');
        $this->sql("CREATE INDEX `v2_folderpairs_rightAccountId_idx` ON `v2_folderpairs` ( `rightAccountId` )", view: 'count');
        $this->sql("CREATE INDEX `v2_folderpair_synced_files_folderPairId_idx` ON `v2_folderpair_synced_files` ( `folderPairId` )", view: 'count');
        $this->sql("CREATE INDEX `v2_folderpair_synced_files_itemKey_idx` ON `v2_folderpair_synced_files` ( `itemKey` )", view: 'count');
        $this->sql("CREATE INDEX `v2_sync_logs_createdDate_idx` ON `v2_sync_logs` ( `createdDate` )", view: 'count');
        $this->sql("CREATE INDEX `v2_sync_log_items_syncLogId_idx` ON `v2_sync_log_items` ( `syncLogId` )", view: 'count');
        $this->sql("CREATE INDEX `v2_webhook_properties_webhookId_idx` ON `v2_webhook_properties` ( `webhookId` )", view: 'count');

        $this->sql("INSERT INTO accounts VALUES(NULL,NULL,'LocalStorage',0,0,0,NULL,NULL,NULL,NULL,0,'2023-09-02 18:36:22.679000',0,NULL,NULL,1,NULL,NULL,0,0,NULL,NULL,NULL,0,'SD CARD',NULL,0,'SD CARD',NULL,NULL,NULL,0,NULL,0,0)", view: 'count');
        $this->sql("INSERT INTO accounts VALUES(NULL,NULL,'SFTP',0,0,0,NULL,'UTF8',NULL,NULL,0,'2023-09-04 17:49:54.821000',0,NULL,NULL,2,NULL,'',0,0,NULL,'/storage/emulated/0/Download/id_rsa','root',0,'SFTP',NULL,$port,NULL,'','UsStandard','{$this->ip}',0,NULL,1,0)", view: 'count');
        $dirs = array_filter(explode("\n", file_get_contents('/configs/dirs')));
        foreach ($dirs as $k => $v) {
            $this->sql("INSERT INTO folderpairs VALUES(2,1,X'000000000000',NULL,NULL,0,0,0,0,'2023-09-04 17:53:33.325000','SyncOK',0,1,0,NULL,0,NULL,NULL,0,$k,0,1,NULL,1,'2023-09-04 17:57:08.098000','$v',NULL,0,0,0,0,0,0,1,'/var/tmp','/var/tmp',0,0,'$v','$v',0,0,1,'Daily','Skip','Always',1,'ToRemoteFolder',0,0,0,0,0,0,0,0,0,0,1,0,0)", view: 'count');
        }
        $this->sql("INSERT INTO sqlite_sequence VALUES('accounts',3)", view: 'count');
        $this->sql("INSERT INTO sqlite_sequence VALUES('folderpairs',5)", view: 'count');


        $this->sendFile($this->input['from'], curl_file_create($this->db), 'импортируйте через <i><b>настройки->резервное копирование->восстановление базы данных</b></i>', $this->input['message_id']);
        $this->sendFile($this->input['from'], curl_file_create('/root/.ssh/id_rsa'), 'сохраните в загрузки <code>/storage/emulated/0/Download/</code>', $this->input['message_id']);
        $this->send($this->input['from'], "сервер sftp: <code>ssh -i /storage/emulated/0/Download/id_rsa root@{$this->ip} -p $port</code>", $this->input['message_id']);
        $this->answer($this->input['callback_id']);
        unlink($this->db);
    }

    public function getInfoUser($tgid)
    {
        return $this->request('getChatMember', [
            'chat_id' => $tgid,
            'user_id' => $tgid,
        ]);
    }

    public function uors($text = false, $data = false)
    {
        $text = trim(implode("\n", $text ?: []));
        $data = $data ?: false;
        if (!empty($this->input['callback_id'])) {
            $r = $this->update(
                $this->input['chat'],
                $this->input['message_id'],
                $text,
                $data,
            );
        } else {
            $r = $this->send(
                $this->input['chat'],
                $text,
                $this->input['message_id'],
                $data,
            );
        }
        return $r;
    }

    public function reply()
    {
        $this->delete($this->input['chat'], $this->input['reply']);
        $this->delete($this->input['chat'], $this->input['message_id']);
        $callback = $_SESSION['reply'][$this->input['reply']]['callback'];
        $this->input['message_id']  = $this->input['callback_id'] = $_SESSION['reply'][$this->input['reply']]['start_message'];
        $this->{$callback}($this->input['message'], ...$_SESSION['reply'][$this->input['reply']]['args']);
        $this->answer($_SESSION['reply'][$this->input['reply']]['start_message']);
        unset($_SESSION['reply'][$this->input['reply']]);
    }

    public function sendReply($message, $callback, ...$args)
    {
        $r = $this->send(
            $this->input['chat'],
            "@{$this->input['username']} $message",
            $this->input['message_id'],
            reply: $message,
        );
        $_SESSION['reply'][$r['result']['message_id']] = [
            'start_message'  => $this->input['message_id'],
            'start_callback' => $this->input['callback_id'],
            'callback'       => $callback,
            'args'           => $args,
        ];
    }

    // debug method
    public function sd($var, $log = false, $json = false, $raw = false)
    {
        if ($log) {
            if ($json) {
                file_put_contents('/logs/debug', json_encode($var, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } elseif ($raw) {
                file_put_contents('/logs/debug', $var);
            } else {
                file_put_contents('/logs/debug', var_export($var, true));
            }
        }
        return $this->send(array_key_first($this->getAdmins()), debug_backtrace()[0]['line'] . ":\n" . var_export($var, true));
    }

    public function request($method, $data, $json_header = 0)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->api . $method,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $json_header ? [
                'Content-Type: application/json'
            ] : [],
            CURLOPT_POSTFIELDS => $data,
            // CURLOPT_TIMEOUT    => 5,
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $res;
    }

    public function callbackCheck()
    {
        if (empty($this->callback) && !empty($this->input['callback_id'])) {
            $r = $this->answer($this->input['callback_id'], $GLOBALS['debug'] ? $this->input['callback'] : false);
        }
    }

    public function getcommands($lang = false, $scope = false)
    {
        return $this->request('getMyCommands', [
            'language_code' => $lang ?: '',
            'scope'         => json_encode($scope ?: ['type' => 'default']),
        ])['result'];
    }

    public function notify($text)
    {
        foreach ($this->getAdmins() as $k => $v) {
            $this->send($k, $text);
        }
    }

    public function setcommands($data)
    {
        return $this->request('setMyCommands', json_encode($data), 1);
    }

    public function delcommands($data)
    {
        return $this->request('deleteMyCommands', json_encode($data), 1);
    }

    public function send($chat, $text, ?int $to = 0, $button = false, $reply = false, $mode = 'HTML', $entities = false, $forum = false)
    {
        $data = [
            'chat_id'             => $chat,
            'text'                => trim($text) ? trim($text) : '...',
            'reply_to_message_id' => $to,
        ];
        // кнопки или реплай
        if (false !== $reply) {
            $extra = [
                'force_reply'             => true,
                'input_field_placeholder' => $reply,
                'selective'               => true,
            ];
        } elseif ($button) {
            $extra = ['inline_keyboard' => $button];
        }
        if (!empty($extra)) {
            $data['reply_markup'] = json_encode($extra);
        }
        if ($entities) {
            $data['entities'] = json_encode($entities);
        } else {
            $data['parse_mode'] = $mode;
        }
        if ($forum) {
            $data['message_thread_id'] = $forum;
        }
        return $this->request('sendMessage', $data);
    }

    public function update($chat, $message_id, $text, $button = false, $mode = 'HTML')
    {
        $data = [
            'chat_id'    => $chat,
            'message_id' => $message_id,
            'text'       => trim($text) ? trim($text) : '...',
            'parse_mode' => $mode,
        ];
        if (!empty($button)) {
            $data['reply_markup'] = json_encode(['inline_keyboard' => $button]);
        }
        return $this->request('editMessageText', $data);
    }

    public function sendPhoto($chat, $id_url_cFile, $caption = false, $to = false)
    {
        $data = [
            'chat_id'             => $chat,
            'photo'               => $id_url_cFile,
            'caption'             => $caption,
            'reply_to_message_id' => $to,
        ];
        return $this->request('sendPhoto', $data);
    }

    public function sendVideo($chat, $id_url_cFile, $caption = false, $to = false)
    {
        $data = [
            'chat_id'             => $chat,
            'video'               => $id_url_cFile,
            'caption'             => $caption,
            'reply_to_message_id' => $to,
        ];
        return $this->request('sendVideo', $data);
    }

    public function sendFile($chat, $id_url_cFile, $caption = false, $to = false)
    {
        return $this->request('sendDocument', [
            'chat_id'             => $chat,
            'document'            => $id_url_cFile,
            'caption'             => $caption,
            'reply_to_message_id' => $to,
            'parse_mode'          => 'HTML',
        ]);
    }

    public function answer($callback_id, $textNotify = false, $notify = false)
    {
        return $this->callback = $this->request('answerCallbackQuery', [
            'callback_query_id' => $callback_id,
            'show_alert'        => $notify,
            'text'              => $textNotify,
        ]);
    }

    public function delete($chat, $message_id)
    {
        $data = [
            'chat_id'    => $chat,
            'message_id' => $message_id,
        ];
        return $this->request('deleteMessage', $data);
    }

    public function sql(string $sql, array $values = [], string $view = 'all', int $column = 0, $nextRowset = 0)
    {
        if ('query' == $view) {
            $keys = [];
            foreach ($values as $k => $v) {
                if (is_string($k)) {
                    $sql = preg_replace('~' . preg_quote($k) . '~', "'" . $v . "'", $sql);
                } else {
                    $sql = preg_replace('~[?]~', "'" . $v . "'", $sql, 1);
                }
            }
            return $sql;
        }
        $dbh = new PDO("sqlite:{$this->db}", '', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $stmt = $dbh->prepare($sql);
        if ($stmt->execute($values)) {
            while ($nextRowset) {
                $stmt->nextRowset();
                $nextRowset--;
            }
            switch ($view) {
                case 'count':
                    return $stmt->rowCount();
                case 'row':
                    return $stmt->fetch();
                case 'one':
                    return $stmt->fetchColumn($column);
                case 'column':
                    return $stmt->fetchAll(PDO::FETCH_COLUMN, $column);
                case 'uniq':
                    return $stmt->fetchAll(PDO::FETCH_UNIQUE);

                default:
                    return $stmt->fetchAll();
            }
        } else {
            return false;
        }
    }
}
