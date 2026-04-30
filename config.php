<?php
$host = "sql113.infinityfree.com";
$dbname = "if0_41664315_ghareb_01";
$dbuser = "if0_41664315";
$dbpass = "BXkmKkywpBaoc";

try {
    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    if (extension_loaded('pdo_mysql')) {
        $pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4 COLLATE utf8mb4_general_ci';
    }

    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass, $pdoOptions);

    function quoteMysqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    function ensureMysqlUtf8mb4Defaults(PDO $pdo, string $databaseName): void
    {
        if ($databaseName !== '') {
            try {
                $pdo->exec(
                    'ALTER DATABASE '
                    . quoteMysqlIdentifier($databaseName)
                    . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                );
            } catch (PDOException $exception) {
                error_log('تعذر تحديث ترميز قاعدة البيانات إلى UTF-8: ' . $exception->getMessage());
            }
        }

        try {
            $tableStatusStmt = $pdo->query('SHOW TABLE STATUS');
            $tableStatuses = $tableStatusStmt ? $tableStatusStmt->fetchAll(PDO::FETCH_ASSOC) : [];

            foreach ($tableStatuses as $tableStatus) {
                $tableName = trim((string) ($tableStatus['Name'] ?? ''));
                $tableCollation = strtolower(trim((string) ($tableStatus['Collation'] ?? '')));

                if ($tableName === '' || $tableCollation === 'utf8mb4_general_ci') {
                    continue;
                }

                try {
                    $pdo->exec(
                        'ALTER TABLE '
                        . quoteMysqlIdentifier($tableName)
                        . ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
                    );
                } catch (PDOException $exception) {
                    error_log(sprintf('تعذر تحديث ترميز الجدول %s إلى UTF-8: %s', $tableName, $exception->getMessage()));
                }
            }
        } catch (PDOException $exception) {
            error_log('تعذر قراءة حالة جداول قاعدة البيانات للتحقق من الترميز: ' . $exception->getMessage());
        }
    }

    $usersTableExists = $pdo->query("SHOW TABLES LIKE 'users'");
    $coachesTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'coaches'");
    $coachesTableExists = $coachesTableExistsStmt && $coachesTableExistsStmt->rowCount() > 0;
    $administratorsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'administrators'");
    $administratorsTableExists = $administratorsTableExistsStmt && $administratorsTableExistsStmt->rowCount() > 0;
    $coachAttendanceTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'coach_attendance'");
    $coachAttendanceTableExists = $coachAttendanceTableExistsStmt && $coachAttendanceTableExistsStmt->rowCount() > 0;
    $coachAdvancesTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'coach_advances'");
    $coachAdvancesTableExists = $coachAdvancesTableExistsStmt && $coachAdvancesTableExistsStmt->rowCount() > 0;
    $adminAdvancesTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'admin_advances'");
    $adminAdvancesTableExists = $adminAdvancesTableExistsStmt && $adminAdvancesTableExistsStmt->rowCount() > 0;
    $coachSalaryPaymentsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'coach_salary_payments'");
    $coachSalaryPaymentsTableExists = $coachSalaryPaymentsTableExistsStmt && $coachSalaryPaymentsTableExistsStmt->rowCount() > 0;
    $adminSalaryPaymentsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'admin_salary_payments'");
    $adminSalaryPaymentsTableExists = $adminSalaryPaymentsTableExistsStmt && $adminSalaryPaymentsTableExistsStmt->rowCount() > 0;
    $administratorAttendanceTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'administrator_attendance'");
    $administratorAttendanceTableExists = $administratorAttendanceTableExistsStmt && $administratorAttendanceTableExistsStmt->rowCount() > 0;
    $subscriptionsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'subscriptions'");
    $subscriptionsTableExists = $subscriptionsTableExistsStmt && $subscriptionsTableExistsStmt->rowCount() > 0;
    $needsPermissionsMigration = false;

    if ($usersTableExists && $usersTableExists->rowCount() > 0) {
        $permissionsColumn = $pdo->query("SHOW COLUMNS FROM users LIKE 'permissions'");
        $needsPermissionsMigration = !$permissionsColumn || $permissionsColumn->rowCount() === 0;
    }

    ensureMysqlUtf8mb4Defaults($pdo, $dbname);

    $createTable = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('مدير', 'مشرف') NOT NULL,
            permissions TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createTable);

    if ($needsPermissionsMigration) {
        $pdo->exec("ALTER TABLE users ADD COLUMN permissions TEXT NULL AFTER role");
    }

    $createCoachesTable = "
        CREATE TABLE IF NOT EXISTS coaches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(25) NOT NULL,
            password_hash VARCHAR(255) NULL,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            image_files LONGTEXT NULL,
            transfer_number VARCHAR(100) NULL,
            transfer_type ENUM('wallet', 'instapay') NULL,
            UNIQUE KEY coaches_phone_unique (phone),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createCoachesTable);

    // إضافة أعمدة التحويل إذا كانت مفقودة (للتحديثات)
    $transferNumberColumnExists = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'transfer_number'")->rowCount() > 0;
    if (!$transferNumberColumnExists) {
        $pdo->exec("ALTER TABLE coaches ADD COLUMN transfer_number VARCHAR(100) NULL");
    }
    $transferTypeColumnExists = $pdo->query("SHOW COLUMNS FROM coaches LIKE 'transfer_type'")->rowCount() > 0;
    if (!$transferTypeColumnExists) {
        $pdo->exec("ALTER TABLE coaches ADD COLUMN transfer_type ENUM('wallet', 'instapay') NULL");
    }

    $createAdministratorsTable = "
        CREATE TABLE IF NOT EXISTS administrators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            phone VARCHAR(25) NOT NULL,
            password_hash VARCHAR(255) NULL,
            barcode VARCHAR(100) NULL,
            leave_days LONGTEXT NULL,
            image_files LONGTEXT NULL,
            UNIQUE KEY administrators_barcode_unique (barcode),
            UNIQUE KEY administrators_phone_unique (phone),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAdministratorsTable);

    $createCoachAttendanceTable = "
        CREATE TABLE IF NOT EXISTS coach_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            work_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY coach_attendance_unique_day (coach_id, attendance_date),
            KEY coach_attendance_date_idx (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createCoachAttendanceTable);

    $createAdministratorAttendanceTable = "
        CREATE TABLE IF NOT EXISTS administrator_attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            administrator_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in_time DATETIME NOT NULL,
            check_out_time DATETIME NULL,
            work_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY administrator_attendance_unique_day (administrator_id, attendance_date),
            KEY administrator_attendance_date_idx (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAdministratorAttendanceTable);

    $createCoachAdvancesTable = "
        CREATE TABLE IF NOT EXISTS coach_advances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            advance_date DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY coach_advances_date_idx (advance_date),
            KEY coach_advances_coach_idx (coach_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createCoachAdvancesTable);

    $createAdminAdvancesTable = "
        CREATE TABLE IF NOT EXISTS admin_advances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            administrator_id INT NOT NULL,
            advance_date DATE NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY admin_advances_date_idx (advance_date),
            KEY admin_advances_administrator_idx (administrator_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAdminAdvancesTable);

    if (!$coachSalaryPaymentsTableExists) {
        $createCoachSalaryPaymentsTable = "
            CREATE TABLE IF NOT EXISTS coach_salary_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                coach_id INT NOT NULL,
                payment_cycle ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
                period_start DATE NULL,
                period_end DATE NULL,
                total_hours DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_advances DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                attendance_days INT NOT NULL DEFAULT 0,
                advance_records_count INT NOT NULL DEFAULT 0,
                payment_status ENUM('pending','paid') NOT NULL DEFAULT 'paid',
                reserved_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                paid_by_user_id INT NULL,
                attendance_snapshot LONGTEXT NULL,
                advances_snapshot LONGTEXT NULL,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                KEY coach_salary_payments_coach_idx (coach_id),
                KEY coach_salary_payments_paid_at_idx (paid_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($createCoachSalaryPaymentsTable);
    }

    if (!$adminSalaryPaymentsTableExists) {
        $createAdminSalaryPaymentsTable = "
            CREATE TABLE admin_salary_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                administrator_id INT NOT NULL,
                payment_cycle ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
                period_start DATE NULL,
                period_end DATE NULL,
                total_hours DECIMAL(7,2) NOT NULL DEFAULT 0.00,
                salary_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total_advances DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                attendance_days INT NOT NULL DEFAULT 0,
                advance_records_count INT NOT NULL DEFAULT 0,
                paid_by_user_id INT NULL,
                attendance_snapshot LONGTEXT NULL,
                advances_snapshot LONGTEXT NULL,
                paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY admin_salary_payments_administrator_idx (administrator_id),
                KEY admin_salary_payments_paid_at_idx (paid_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($createAdminSalaryPaymentsTable);
        $adminSalaryPaymentsTableExists = true;
    }

    $createInventoryItemsTable = "
        CREATE TABLE IF NOT EXISTS inventory_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(150) NOT NULL,
            track_quantity TINYINT(1) NOT NULL DEFAULT 1,
            quantity INT NULL DEFAULT NULL,
            purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY inventory_items_name_unique (item_name),
            KEY inventory_items_track_quantity_idx (track_quantity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createInventoryItemsTable);

    $createAcademiesTable = "
        CREATE TABLE IF NOT EXISTS academies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academy_name VARCHAR(150) NOT NULL,
            training_days_count INT NOT NULL,
            training_sessions_count INT NOT NULL,
            subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY academies_name_unique (academy_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAcademiesTable);

    $createAcademyPlayersTable = "
        CREATE TABLE IF NOT EXISTS academy_players (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academy_id INT NOT NULL DEFAULT 0,
            subscription_id INT NULL DEFAULT NULL,
            barcode VARCHAR(100) NULL,
            player_name VARCHAR(150) NOT NULL,
            phone VARCHAR(25) NOT NULL,
            guardian_phone VARCHAR(25) NOT NULL,
            birth_date DATE NULL,
            birth_year INT NULL,
            player_image_path VARCHAR(255) NULL,
            card_request_submitted_at TIMESTAMP NULL DEFAULT NULL,
            subscription_start_date DATE NOT NULL,
            subscription_end_date DATE NOT NULL,
            subscription_name VARCHAR(150) NULL,
            subscription_branch VARCHAR(150) NULL,
            subscription_category VARCHAR(150) NULL,
            subscription_training_days_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_exercises_count INT NOT NULL DEFAULT 0,
            subscription_training_schedule LONGTEXT NULL,
            subscription_coach_name VARCHAR(150) NULL,
            max_trainees INT NOT NULL DEFAULT 0,
            subscription_base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            additional_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            subscription_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            receipt_number VARCHAR(100) NULL,
            birth_certificate_required TINYINT(1) NOT NULL DEFAULT 0,
            birth_certificate_path VARCHAR(255) NULL,
            medical_report_required TINYINT(1) NOT NULL DEFAULT 0,
            medical_report_path VARCHAR(255) NULL,
            medical_report_files LONGTEXT NULL,
            federation_card_required TINYINT(1) NOT NULL DEFAULT 0,
            federation_card_path VARCHAR(255) NULL,
            stars_count TINYINT(3) UNSIGNED NULL,
            last_star_date DATE NULL,
            password_hash VARCHAR(255) NULL,
            created_by_user_id INT NULL,
            renewal_count INT NOT NULL DEFAULT 0,
            last_payment_at TIMESTAMP NULL DEFAULT NULL,
            last_renewed_at TIMESTAMP NULL DEFAULT NULL,
            last_renewed_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY academy_players_academy_idx (academy_id),
            KEY academy_players_subscription_idx (subscription_id),
            KEY academy_players_end_date_idx (subscription_end_date),
            KEY academy_players_remaining_idx (remaining_amount),
            KEY academy_players_name_idx (player_name),
            KEY academy_players_barcode_idx (barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAcademyPlayersTable);

    // إضافة عمود birth_year إذا لم يكن موجوداً
    $birthYearColumnExists = $pdo->query("SHOW COLUMNS FROM academy_players LIKE 'birth_year'")->rowCount() > 0;
    if (!$birthYearColumnExists) {
        $pdo->exec("ALTER TABLE academy_players ADD COLUMN birth_year INT NULL AFTER birth_date");
        // ترحيل البيانات القديمة من birth_date إلى birth_year
        $pdo->exec("UPDATE academy_players SET birth_year = YEAR(birth_date) WHERE birth_date IS NOT NULL AND birth_year IS NULL");
    }

    $createAcademyPlayerPaymentsTable = "
        CREATE TABLE IF NOT EXISTS academy_player_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            payment_type VARCHAR(40) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            receipt_number VARCHAR(100) NULL,
            created_by_user_id INT NULL,
            player_name_snapshot VARCHAR(150) NULL,
            subscription_name_snapshot VARCHAR(150) NULL,
            subscription_amount_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            paid_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            remaining_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            remaining_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY academy_player_payments_player_idx (player_id),
            KEY academy_player_payments_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAcademyPlayerPaymentsTable);

    $createAcademyStoreProductsTable = "
        CREATE TABLE IF NOT EXISTS academy_store_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_name VARCHAR(150) NOT NULL,
            product_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            product_image_path VARCHAR(255) NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY academy_store_products_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createAcademyStoreProductsTable);

    $createSwimmerCardRequestsTable = "
        CREATE TABLE IF NOT EXISTS swimmer_card_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            player_id INT NOT NULL,
            player_name_snapshot VARCHAR(150) NOT NULL,
            request_image_path VARCHAR(255) NOT NULL,
            approved_exported_at TIMESTAMP NULL DEFAULT NULL,
            approved_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY swimmer_card_requests_player_idx (player_id),
            KEY swimmer_card_requests_created_at_idx (created_at),
            KEY swimmer_card_requests_approved_exported_at_idx (approved_exported_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSwimmerCardRequestsTable);

    $createSwimmerNotificationsTable = "
        CREATE TABLE IF NOT EXISTS swimmer_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_message TEXT NOT NULL,
            target_branch VARCHAR(150) NULL,
            target_subscription VARCHAR(150) NULL,
            target_level VARCHAR(150) NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY swimmer_notifications_created_at_idx (created_at),
            KEY swimmer_notifications_branch_idx (target_branch),
            KEY swimmer_notifications_subscription_idx (target_subscription),
            KEY swimmer_notifications_level_idx (target_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSwimmerNotificationsTable);

    $createOffersTable = "
        CREATE TABLE IF NOT EXISTS offers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            offer_title VARCHAR(180) NOT NULL,
            offer_description TEXT NOT NULL,
            valid_from DATE NULL,
            valid_until DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY offers_active_idx (is_active),
            KEY offers_dates_idx (valid_from, valid_until),
            KEY offers_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createOffersTable);

    $createGroupEvaluationsTable = "
        CREATE TABLE IF NOT EXISTS group_evaluations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            evaluation_month CHAR(7) NOT NULL,
            evaluation_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            evaluation_notes TEXT NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY group_evaluations_unique_month (subscription_id, evaluation_month),
            KEY group_evaluations_month_idx (evaluation_month),
            KEY group_evaluations_score_idx (evaluation_score)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createGroupEvaluationsTable);

    $createCoachNotificationsTable = "
        CREATE TABLE IF NOT EXISTS coach_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_message TEXT NOT NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY coach_notifications_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createCoachNotificationsTable);

    $createSubscriptionsTable = "
        CREATE TABLE IF NOT EXISTS subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_name VARCHAR(150) NOT NULL,
            subscription_branch VARCHAR(150) NOT NULL DEFAULT '',
            subscription_category VARCHAR(150) NOT NULL,
            training_days_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
            available_exercises_count INT NOT NULL DEFAULT 1,
            training_schedule LONGTEXT NOT NULL,
            coach_id INT NOT NULL,
            max_trainees INT NOT NULL DEFAULT 1,
            subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY subscriptions_coach_idx (coach_id),
            KEY subscriptions_category_idx (subscription_category),
            CONSTRAINT subscriptions_training_days_count_chk CHECK (training_days_count BETWEEN 1 AND 7),
            CONSTRAINT subscriptions_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSubscriptionsTable);

    $createSwimmerAttendanceSessionsTable = "
        CREATE TABLE IF NOT EXISTS swimmer_attendance_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            subscription_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            opened_by_user_id INT NOT NULL,
            status ENUM('open','closed') NOT NULL DEFAULT 'open',
            total_swimmers INT NOT NULL DEFAULT 0,
            present_count INT NOT NULL DEFAULT 0,
            absent_count INT NOT NULL DEFAULT 0,
            opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            closed_at TIMESTAMP NULL DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY swimmer_attendance_session_unique_day (subscription_id, attendance_date),
            KEY swimmer_attendance_sessions_status_idx (status),
            KEY swimmer_attendance_sessions_opened_by_idx (opened_by_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSwimmerAttendanceSessionsTable);

    $createSwimmerAttendanceRecordsTable = "
        CREATE TABLE IF NOT EXISTS swimmer_attendance_records (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            player_id INT NOT NULL,
            attendance_status ENUM('present','absent') NOT NULL DEFAULT 'absent',
            note TEXT NULL,
            player_snapshot LONGTEXT NULL,
            marked_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY swimmer_attendance_record_unique_player (session_id, player_id),
            KEY swimmer_attendance_records_status_idx (attendance_status),
            KEY swimmer_attendance_records_player_idx (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSwimmerAttendanceRecordsTable);

    $createSiteSettingsTable = "
        CREATE TABLE IF NOT EXISTS site_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            academy_name VARCHAR(150) NOT NULL,
            academy_logo_path VARCHAR(255) NULL,
            facebook_url VARCHAR(255) NULL,
            whatsapp_url VARCHAR(255) NULL,
            youtube_url VARCHAR(255) NULL,
            tiktok_url VARCHAR(255) NULL,
            instagram_url VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSiteSettingsTable);

    $siteSettingsColumns = [
        'facebook_url' => "ALTER TABLE site_settings ADD COLUMN facebook_url VARCHAR(255) NULL AFTER academy_logo_path",
        'whatsapp_url' => "ALTER TABLE site_settings ADD COLUMN whatsapp_url VARCHAR(255) NULL AFTER facebook_url",
        'youtube_url' => "ALTER TABLE site_settings ADD COLUMN youtube_url VARCHAR(255) NULL AFTER whatsapp_url",
        'tiktok_url' => "ALTER TABLE site_settings ADD COLUMN tiktok_url VARCHAR(255) NULL AFTER youtube_url",
        'instagram_url' => "ALTER TABLE site_settings ADD COLUMN instagram_url VARCHAR(255) NULL AFTER tiktok_url",
    ];

    foreach ($siteSettingsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM site_settings LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->fetch(PDO::FETCH_ASSOC) === false) {
            $pdo->exec($alterSql);
        }
    }

    $siteSettingsCountStmt = $pdo->query("SELECT COUNT(*) FROM site_settings");
    $siteSettingsCount = $siteSettingsCountStmt ? (int) $siteSettingsCountStmt->fetchColumn() : 0;

    if ($siteSettingsCount === 0) {
        $insertSiteSettingsStmt = $pdo->prepare("
            INSERT INTO site_settings (academy_name, academy_logo_path, facebook_url, whatsapp_url, youtube_url, tiktok_url, instagram_url)
            VALUES (?, NULL, NULL, NULL, NULL, NULL, NULL)
        ");
        $insertSiteSettingsStmt->execute(['megz Academy']);
    }


    $createSalesInvoicesTable = "
        CREATE TABLE IF NOT EXISTS sales_invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(50) NOT NULL,
            invoice_type ENUM('sale', 'return') NOT NULL DEFAULT 'sale',
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total_profit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            items_count INT NOT NULL DEFAULT 0,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY sales_invoices_number_unique (invoice_number),
            KEY sales_invoices_type_idx (invoice_type),
            KEY sales_invoices_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSalesInvoicesTable);

    $createSalesInvoiceItemsTable = "
        CREATE TABLE IF NOT EXISTS sales_invoice_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            inventory_item_id INT NOT NULL,
            item_name VARCHAR(150) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            unit_purchase_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            unit_sale_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            line_profit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY sales_invoice_items_invoice_idx (invoice_id),
            KEY sales_invoice_items_item_idx (inventory_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSalesInvoiceItemsTable);

    $createSalesInvoicePaymentsTable = "
        CREATE TABLE IF NOT EXISTS sales_invoice_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            invoice_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            payment_note VARCHAR(255) NULL,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY sales_invoice_payments_invoice_idx (invoice_id),
            KEY sales_invoice_payments_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createSalesInvoicePaymentsTable);

    $salesInvoicesColumns = [
        'paid_amount' => "ALTER TABLE sales_invoices ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount",
        'remaining_amount' => "ALTER TABLE sales_invoices ADD COLUMN remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount",
        'guardian_name' => "ALTER TABLE sales_invoices ADD COLUMN guardian_name VARCHAR(150) NULL AFTER remaining_amount",
        'guardian_phone' => "ALTER TABLE sales_invoices ADD COLUMN guardian_phone VARCHAR(25) NULL AFTER guardian_name",
        'payment_status' => "ALTER TABLE sales_invoices ADD COLUMN payment_status ENUM('paid', 'partial') NOT NULL DEFAULT 'paid' AFTER guardian_phone",
    ];
    $salesInvoicesColumnsAdded = false;

    foreach ($salesInvoicesColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM sales_invoices LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
            $salesInvoicesColumnsAdded = true;
        }
    }

    $salesInvoicesIndexes = [
        'sales_invoices_payment_status_idx' => "ALTER TABLE sales_invoices ADD KEY sales_invoices_payment_status_idx (payment_status)",
        'sales_invoices_remaining_amount_idx' => "ALTER TABLE sales_invoices ADD KEY sales_invoices_remaining_amount_idx (remaining_amount)",
    ];

    foreach ($salesInvoicesIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'sales_invoices'
              AND index_name = ?
            LIMIT 1
        ");
        $indexExistsStmt->execute([$indexName]);

        if ($indexExistsStmt->fetchColumn() === false) {
            $pdo->exec($alterSql);
        }
    }

    if ($salesInvoicesColumnsAdded) {
        $pdo->exec("
            UPDATE sales_invoices
            SET
                paid_amount = total_amount,
                remaining_amount = 0.00,
                payment_status = 'paid',
                guardian_name = NULLIF(TRIM(guardian_name), ''),
                guardian_phone = NULLIF(TRIM(guardian_phone), '')
            WHERE paid_amount = 0.00
              AND remaining_amount = 0.00
              AND invoice_type = 'sale'
              AND total_amount > 0.00
        ");
    }

    $createExpensesTable = "
        CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            expense_date DATE NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_by_user_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY expenses_date_idx (expense_date),
            KEY expenses_created_by_idx (created_by_user_id),
            KEY expenses_created_at_idx (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($createExpensesTable);

    if ($coachesTableExists) {
        $coachesColumns = [
            'full_name' => "ALTER TABLE coaches ADD COLUMN full_name VARCHAR(150) NOT NULL AFTER id",
            'phone' => "ALTER TABLE coaches ADD COLUMN phone VARCHAR(25) NOT NULL AFTER full_name",
            'password_hash' => "ALTER TABLE coaches ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone",
            'hourly_rate' => "ALTER TABLE coaches ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER password_hash",
            'image_files' => "ALTER TABLE coaches ADD COLUMN image_files LONGTEXT NULL AFTER hourly_rate",
            'created_at' => "ALTER TABLE coaches ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER image_files",
            'updated_at' => "ALTER TABLE coaches ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($coachesColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM coaches LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $coachPhoneUniqueIndex = $pdo->query("SHOW INDEX FROM coaches WHERE Key_name = 'coaches_phone_unique'");
        if (!$coachPhoneUniqueIndex || $coachPhoneUniqueIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coaches ADD UNIQUE KEY coaches_phone_unique (phone)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء المفتاح الفريد لهواتف المدربين');
            }
        }
    }

    if ($administratorsTableExists) {
        $administratorsColumns = [
            'full_name' => "ALTER TABLE administrators ADD COLUMN full_name VARCHAR(150) NOT NULL AFTER id",
            'phone' => "ALTER TABLE administrators ADD COLUMN phone VARCHAR(25) NOT NULL AFTER full_name",
            'password_hash' => "ALTER TABLE administrators ADD COLUMN password_hash VARCHAR(255) NULL AFTER phone",
            'barcode' => "ALTER TABLE administrators ADD COLUMN barcode VARCHAR(100) NULL AFTER password_hash",
            'leave_days' => "ALTER TABLE administrators ADD COLUMN leave_days LONGTEXT NULL AFTER barcode",
            'image_files' => "ALTER TABLE administrators ADD COLUMN image_files LONGTEXT NULL AFTER leave_days",
            'created_at' => "ALTER TABLE administrators ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER image_files",
            'updated_at' => "ALTER TABLE administrators ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($administratorsColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM administrators LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $administratorsPhoneUniqueIndex = $pdo->query("SHOW INDEX FROM administrators WHERE Key_name = 'administrators_phone_unique'");
        if (!$administratorsPhoneUniqueIndex || $administratorsPhoneUniqueIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE administrators ADD UNIQUE KEY administrators_phone_unique (phone)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء المفتاح الفريد لهواتف الإداريين');
            }
        }

        $administratorsBarcodeUniqueIndex = $pdo->query("SHOW INDEX FROM administrators WHERE Key_name = 'administrators_barcode_unique'");
        if (!$administratorsBarcodeUniqueIndex || $administratorsBarcodeUniqueIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE administrators ADD UNIQUE KEY administrators_barcode_unique (barcode)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء المفتاح الفريد لباركود الإداريين');
            }
        }
    }

    if ($coachAttendanceTableExists) {
        $coachAttendanceColumns = [
            'coach_id' => "ALTER TABLE coach_attendance ADD COLUMN coach_id INT NOT NULL AFTER id",
            'attendance_date' => "ALTER TABLE coach_attendance ADD COLUMN attendance_date DATE NOT NULL AFTER coach_id",
            'work_hours' => "ALTER TABLE coach_attendance ADD COLUMN work_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER attendance_date",
            'created_at' => "ALTER TABLE coach_attendance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER work_hours",
            'updated_at' => "ALTER TABLE coach_attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($coachAttendanceColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM coach_attendance LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $attendanceUniqueIndex = $pdo->query("SHOW INDEX FROM coach_attendance WHERE Key_name = 'coach_attendance_unique_day'");
        if (!$attendanceUniqueIndex || $attendanceUniqueIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_attendance ADD UNIQUE KEY coach_attendance_unique_day (coach_id, attendance_date)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء المفتاح الفريد لحضور المدربين اليومي');
            }
        }

        $attendanceDateIndex = $pdo->query("SHOW INDEX FROM coach_attendance WHERE Key_name = 'coach_attendance_date_idx'");
        if (!$attendanceDateIndex || $attendanceDateIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_attendance ADD KEY coach_attendance_date_idx (attendance_date)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس تاريخ حضور المدربين');
            }
        }
    }

    if ($administratorAttendanceTableExists) {
        $administratorAttendanceColumns = [
            'administrator_id' => "ALTER TABLE administrator_attendance ADD COLUMN administrator_id INT NOT NULL AFTER id",
            'attendance_date' => "ALTER TABLE administrator_attendance ADD COLUMN attendance_date DATE NOT NULL AFTER administrator_id",
            'check_in_time' => "ALTER TABLE administrator_attendance ADD COLUMN check_in_time DATETIME NOT NULL AFTER attendance_date",
            'check_out_time' => "ALTER TABLE administrator_attendance ADD COLUMN check_out_time DATETIME NULL AFTER check_in_time",
            'work_hours' => "ALTER TABLE administrator_attendance ADD COLUMN work_hours DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER check_out_time",
            'created_at' => "ALTER TABLE administrator_attendance ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER work_hours",
            'updated_at' => "ALTER TABLE administrator_attendance ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($administratorAttendanceColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM administrator_attendance LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $administratorAttendanceUniqueIndex = $pdo->query("SHOW INDEX FROM administrator_attendance WHERE Key_name = 'administrator_attendance_unique_day'");
        if (!$administratorAttendanceUniqueIndex || $administratorAttendanceUniqueIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE administrator_attendance ADD UNIQUE KEY administrator_attendance_unique_day (administrator_id, attendance_date)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء المفتاح الفريد لحضور الإداريين اليومي: ' . $exception->getMessage());
            }
        }

        $administratorAttendanceDateIndex = $pdo->query("SHOW INDEX FROM administrator_attendance WHERE Key_name = 'administrator_attendance_date_idx'");
        if (!$administratorAttendanceDateIndex || $administratorAttendanceDateIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE administrator_attendance ADD KEY administrator_attendance_date_idx (attendance_date)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس تاريخ حضور الإداريين: ' . $exception->getMessage());
            }
        }
    }

    $academyPlayersColumns = [
        'academy_id' => "ALTER TABLE academy_players ADD COLUMN academy_id INT NOT NULL DEFAULT 0 AFTER id",
        'subscription_id' => "ALTER TABLE academy_players ADD COLUMN subscription_id INT NULL DEFAULT NULL AFTER academy_id",
        'barcode' => "ALTER TABLE academy_players ADD COLUMN barcode VARCHAR(100) NULL AFTER subscription_id",
        'player_name' => "ALTER TABLE academy_players ADD COLUMN player_name VARCHAR(150) NOT NULL AFTER barcode",
        'phone' => "ALTER TABLE academy_players ADD COLUMN phone VARCHAR(25) NOT NULL AFTER player_name",
        'guardian_phone' => "ALTER TABLE academy_players ADD COLUMN guardian_phone VARCHAR(25) NOT NULL AFTER phone",
        'birth_date' => "ALTER TABLE academy_players ADD COLUMN birth_date DATE NULL AFTER guardian_phone",
        'player_image_path' => "ALTER TABLE academy_players ADD COLUMN player_image_path VARCHAR(255) NULL AFTER birth_date",
        'card_request_submitted_at' => "ALTER TABLE academy_players ADD COLUMN card_request_submitted_at TIMESTAMP NULL DEFAULT NULL AFTER player_image_path",
        'subscription_start_date' => "ALTER TABLE academy_players ADD COLUMN subscription_start_date DATE NOT NULL AFTER card_request_submitted_at",
        'subscription_end_date' => "ALTER TABLE academy_players ADD COLUMN subscription_end_date DATE NOT NULL AFTER subscription_start_date",
        'subscription_name' => "ALTER TABLE academy_players ADD COLUMN subscription_name VARCHAR(150) NULL AFTER subscription_end_date",
        'subscription_branch' => "ALTER TABLE academy_players ADD COLUMN subscription_branch VARCHAR(150) NULL AFTER subscription_name",
        'subscription_category' => "ALTER TABLE academy_players ADD COLUMN subscription_category VARCHAR(150) NULL AFTER subscription_branch",
        'subscription_training_days_count' => "ALTER TABLE academy_players ADD COLUMN subscription_training_days_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER subscription_category",
        'available_exercises_count' => "ALTER TABLE academy_players ADD COLUMN available_exercises_count INT NOT NULL DEFAULT 0 AFTER subscription_training_days_count",
        'subscription_training_schedule' => "ALTER TABLE academy_players ADD COLUMN subscription_training_schedule LONGTEXT NULL AFTER available_exercises_count",
        'subscription_coach_name' => "ALTER TABLE academy_players ADD COLUMN subscription_coach_name VARCHAR(150) NULL AFTER subscription_training_schedule",
        'max_trainees' => "ALTER TABLE academy_players ADD COLUMN max_trainees INT NOT NULL DEFAULT 0 AFTER subscription_coach_name",
        'subscription_base_price' => "ALTER TABLE academy_players ADD COLUMN subscription_base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER max_trainees",
        'additional_discount' => "ALTER TABLE academy_players ADD COLUMN additional_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subscription_base_price",
        'subscription_amount' => "ALTER TABLE academy_players ADD COLUMN subscription_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER additional_discount",
        'paid_amount' => "ALTER TABLE academy_players ADD COLUMN paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subscription_amount",
        'remaining_amount' => "ALTER TABLE academy_players ADD COLUMN remaining_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount",
        'receipt_number' => "ALTER TABLE academy_players ADD COLUMN receipt_number VARCHAR(100) NULL AFTER remaining_amount",
        'birth_certificate_required' => "ALTER TABLE academy_players ADD COLUMN birth_certificate_required TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_number",
        'birth_certificate_path' => "ALTER TABLE academy_players ADD COLUMN birth_certificate_path VARCHAR(255) NULL AFTER birth_certificate_required",
        'medical_report_required' => "ALTER TABLE academy_players ADD COLUMN medical_report_required TINYINT(1) NOT NULL DEFAULT 0 AFTER birth_certificate_path",
        'medical_report_path' => "ALTER TABLE academy_players ADD COLUMN medical_report_path VARCHAR(255) NULL AFTER medical_report_required",
        'medical_report_files' => "ALTER TABLE academy_players ADD COLUMN medical_report_files LONGTEXT NULL AFTER medical_report_path",
        'federation_card_required' => "ALTER TABLE academy_players ADD COLUMN federation_card_required TINYINT(1) NOT NULL DEFAULT 0 AFTER medical_report_files",
        'federation_card_path' => "ALTER TABLE academy_players ADD COLUMN federation_card_path VARCHAR(255) NULL AFTER federation_card_required",
        'stars_count' => "ALTER TABLE academy_players ADD COLUMN stars_count TINYINT(3) UNSIGNED NULL AFTER federation_card_path",
        'last_star_date' => "ALTER TABLE academy_players ADD COLUMN last_star_date DATE NULL AFTER stars_count",
        'password_hash' => "ALTER TABLE academy_players ADD COLUMN password_hash VARCHAR(255) NULL AFTER last_star_date",
        'created_by_user_id' => "ALTER TABLE academy_players ADD COLUMN created_by_user_id INT NULL AFTER password_hash",
        'renewal_count' => "ALTER TABLE academy_players ADD COLUMN renewal_count INT NOT NULL DEFAULT 0 AFTER created_by_user_id",
        'last_payment_at' => "ALTER TABLE academy_players ADD COLUMN last_payment_at TIMESTAMP NULL DEFAULT NULL AFTER renewal_count",
        'last_renewed_at' => "ALTER TABLE academy_players ADD COLUMN last_renewed_at TIMESTAMP NULL DEFAULT NULL AFTER last_payment_at",
        'last_renewed_by_user_id' => "ALTER TABLE academy_players ADD COLUMN last_renewed_by_user_id INT NULL AFTER last_renewed_at",
        'created_at' => "ALTER TABLE academy_players ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER last_renewed_by_user_id",
        'updated_at' => "ALTER TABLE academy_players ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($academyPlayersColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM academy_players LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    // إضافة عمود birth_year إذا لم يكن موجوداً (تم إضافته سابقاً ولكن نضمنه مرة أخرى)
    $birthYearColumnExists = $pdo->query("SHOW COLUMNS FROM academy_players LIKE 'birth_year'")->rowCount() > 0;
    if (!$birthYearColumnExists) {
        $pdo->exec("ALTER TABLE academy_players ADD COLUMN birth_year INT NULL AFTER birth_date");
        $pdo->exec("UPDATE academy_players SET birth_year = YEAR(birth_date) WHERE birth_date IS NOT NULL AND birth_year IS NULL");
    }

    try {
        // Convert legacy single-image medical reports into the new multi-image JSON storage.
        $legacyMedicalReportsStmt = $pdo->query("
            SELECT id, medical_report_path
            FROM academy_players
            WHERE medical_report_path IS NOT NULL
              AND medical_report_path <> ''
              AND (medical_report_files IS NULL OR medical_report_files = '')
        ");
        $legacyMedicalReports = $legacyMedicalReportsStmt ? ($legacyMedicalReportsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        if ($legacyMedicalReports !== []) {
            $updateLegacyMedicalReportsStmt = $pdo->prepare('UPDATE academy_players SET medical_report_files = ? WHERE id = ?');
            foreach ($legacyMedicalReports as $legacyMedicalReport) {
                $encodedFiles = json_encode([(string) ($legacyMedicalReport['medical_report_path'] ?? '')], JSON_UNESCAPED_UNICODE);
                if ($encodedFiles === false) {
                    error_log('تعذر ترحيل التقرير الطبي متعدد الصور للسباح بمعرف: ' . (string) ($legacyMedicalReport['id'] ?? 'غير معروف'));
                    continue;
                }

                $updateLegacyMedicalReportsStmt->execute([
                    $encodedFiles,
                    (int) ($legacyMedicalReport['id'] ?? 0),
                ]);
            }
        }
    } catch (PDOException $exception) {
        error_log('تعذر ترحيل ملفات التقارير الطبية المتعددة: ' . $exception->getMessage());
    }

    $academyPlayersIndexes = [
        'academy_players_academy_idx' => "ALTER TABLE academy_players ADD KEY academy_players_academy_idx (academy_id)",
        'academy_players_subscription_idx' => "ALTER TABLE academy_players ADD KEY academy_players_subscription_idx (subscription_id)",
        'academy_players_end_date_idx' => "ALTER TABLE academy_players ADD KEY academy_players_end_date_idx (subscription_end_date)",
        'academy_players_remaining_idx' => "ALTER TABLE academy_players ADD KEY academy_players_remaining_idx (remaining_amount)",
        'academy_players_name_idx' => "ALTER TABLE academy_players ADD KEY academy_players_name_idx (player_name)",
        'academy_players_barcode_idx' => "ALTER TABLE academy_players ADD KEY academy_players_barcode_idx (barcode)",
    ];

    foreach ($academyPlayersIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM academy_players WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس لاعبي الأكاديميات: ' . $indexName . ' - ' . $exception->getMessage());
            }
        }
    }

    $academyPlayerPaymentsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'academy_player_payments'");
    $academyPlayerPaymentsTableExists = $academyPlayerPaymentsTableExistsStmt && $academyPlayerPaymentsTableExistsStmt->rowCount() > 0;

    if (!$academyPlayerPaymentsTableExists) {
        $createAcademyPlayerPaymentsTable = "
            CREATE TABLE IF NOT EXISTS academy_player_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                player_id INT NOT NULL,
                payment_type VARCHAR(40) NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                receipt_number VARCHAR(100) NULL,
                created_by_user_id INT NULL,
                player_name_snapshot VARCHAR(150) NULL,
                subscription_name_snapshot VARCHAR(150) NULL,
                subscription_amount_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                paid_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                paid_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                remaining_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                remaining_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY academy_player_payments_player_idx (player_id),
                KEY academy_player_payments_created_at_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($createAcademyPlayerPaymentsTable);
    }

    $academyPlayerPaymentsColumns = [
        'player_name_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN player_name_snapshot VARCHAR(150) NULL AFTER created_by_user_id",
        'subscription_name_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN subscription_name_snapshot VARCHAR(150) NULL AFTER player_name_snapshot",
        'subscription_amount_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN subscription_amount_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subscription_name_snapshot",
        'paid_amount_before_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN paid_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subscription_amount_snapshot",
        'paid_amount_after_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN paid_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount_before_snapshot",
        'remaining_amount_before_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN remaining_amount_before_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER paid_amount_after_snapshot",
        'remaining_amount_after_snapshot' => "ALTER TABLE academy_player_payments ADD COLUMN remaining_amount_after_snapshot DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER remaining_amount_before_snapshot",
    ];

    foreach ($academyPlayerPaymentsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM academy_player_payments LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $academyStoreProductsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'academy_store_products'");
    $academyStoreProductsTableExists = $academyStoreProductsTableExistsStmt && $academyStoreProductsTableExistsStmt->rowCount() > 0;

    if (!$academyStoreProductsTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS academy_store_products (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_name VARCHAR(150) NOT NULL,
                product_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                product_image_path VARCHAR(255) NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY academy_store_products_created_at_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $academyStoreProductsColumns = [
        'product_name' => "ALTER TABLE academy_store_products ADD COLUMN product_name VARCHAR(150) NOT NULL AFTER id",
        'product_price' => "ALTER TABLE academy_store_products ADD COLUMN product_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER product_name",
        'product_image_path' => "ALTER TABLE academy_store_products ADD COLUMN product_image_path VARCHAR(255) NULL AFTER product_price",
        'created_by_user_id' => "ALTER TABLE academy_store_products ADD COLUMN created_by_user_id INT NULL AFTER product_image_path",
        'created_at' => "ALTER TABLE academy_store_products ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE academy_store_products ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($academyStoreProductsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM academy_store_products LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $academyStoreProductsCreatedAtIndex = $pdo->query("SHOW INDEX FROM academy_store_products WHERE Key_name = 'academy_store_products_created_at_idx'");
    if (!$academyStoreProductsCreatedAtIndex || $academyStoreProductsCreatedAtIndex->rowCount() === 0) {
        try {
            $pdo->exec("ALTER TABLE academy_store_products ADD KEY academy_store_products_created_at_idx (created_at)");
        } catch (PDOException $exception) {
            error_log('تعذر إنشاء فهرس متجر السباحين: ' . $exception->getMessage());
        }
    }

    $swimmerCardRequestsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'swimmer_card_requests'");
    $swimmerCardRequestsTableExists = $swimmerCardRequestsTableExistsStmt && $swimmerCardRequestsTableExistsStmt->rowCount() > 0;

    if (!$swimmerCardRequestsTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS swimmer_card_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                player_id INT NOT NULL,
                player_name_snapshot VARCHAR(150) NOT NULL,
                request_image_path VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY swimmer_card_requests_player_idx (player_id),
                KEY swimmer_card_requests_created_at_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

        $swimmerCardRequestsColumns = [
            'player_id' => "ALTER TABLE swimmer_card_requests ADD COLUMN player_id INT NOT NULL AFTER id",
            'player_name_snapshot' => "ALTER TABLE swimmer_card_requests ADD COLUMN player_name_snapshot VARCHAR(150) NOT NULL AFTER player_id",
            'request_image_path' => "ALTER TABLE swimmer_card_requests ADD COLUMN request_image_path VARCHAR(255) NOT NULL AFTER player_name_snapshot",
            'approved_exported_at' => "ALTER TABLE swimmer_card_requests ADD COLUMN approved_exported_at TIMESTAMP NULL DEFAULT NULL AFTER request_image_path",
            'approved_by_user_id' => "ALTER TABLE swimmer_card_requests ADD COLUMN approved_by_user_id INT NULL AFTER approved_exported_at",
            'created_at' => "ALTER TABLE swimmer_card_requests ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER approved_by_user_id",
        ];

    foreach ($swimmerCardRequestsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM swimmer_card_requests LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $swimmerCardRequestsIndexes = [
        'swimmer_card_requests_player_idx' => "ALTER TABLE swimmer_card_requests ADD KEY swimmer_card_requests_player_idx (player_id)",
        'swimmer_card_requests_created_at_idx' => "ALTER TABLE swimmer_card_requests ADD KEY swimmer_card_requests_created_at_idx (created_at)",
        'swimmer_card_requests_approved_exported_at_idx' => "ALTER TABLE swimmer_card_requests ADD KEY swimmer_card_requests_approved_exported_at_idx (approved_exported_at)",
    ];

    foreach ($swimmerCardRequestsIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM swimmer_card_requests WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس طلبات الكارنية: ' . $exception->getMessage());
            }
        }
    }

    try {
        $pdo->exec("
            UPDATE academy_players ap
            INNER JOIN (
                SELECT player_id, MIN(created_at) AS first_request_at
                FROM swimmer_card_requests
                GROUP BY player_id
            ) requests ON requests.player_id = ap.id
            SET ap.card_request_submitted_at = requests.first_request_at
            WHERE ap.card_request_submitted_at IS NULL
        ");
    } catch (PDOException $exception) {
        error_log('تعذر تحديث حالة إرسال طلبات الكارنية للاعبين: ' . $exception->getMessage());
    }

    $swimmerNotificationsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'swimmer_notifications'");
    $swimmerNotificationsTableExists = $swimmerNotificationsTableExistsStmt && $swimmerNotificationsTableExistsStmt->rowCount() > 0;

    if (!$swimmerNotificationsTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS swimmer_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_message TEXT NOT NULL,
                target_branch VARCHAR(150) NULL,
                target_subscription VARCHAR(150) NULL,
                target_level VARCHAR(150) NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY swimmer_notifications_created_at_idx (created_at),
                KEY swimmer_notifications_branch_idx (target_branch),
                KEY swimmer_notifications_subscription_idx (target_subscription),
                KEY swimmer_notifications_level_idx (target_level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $swimmerNotificationsColumns = [
        'notification_message' => "ALTER TABLE swimmer_notifications ADD COLUMN notification_message TEXT NOT NULL AFTER id",
        'target_branch' => "ALTER TABLE swimmer_notifications ADD COLUMN target_branch VARCHAR(150) NULL AFTER notification_message",
        'target_subscription' => "ALTER TABLE swimmer_notifications ADD COLUMN target_subscription VARCHAR(150) NULL AFTER target_branch",
        'target_level' => "ALTER TABLE swimmer_notifications ADD COLUMN target_level VARCHAR(150) NULL AFTER target_subscription",
        'created_by_user_id' => "ALTER TABLE swimmer_notifications ADD COLUMN created_by_user_id INT NULL AFTER target_level",
        'created_at' => "ALTER TABLE swimmer_notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE swimmer_notifications ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($swimmerNotificationsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM swimmer_notifications LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $swimmerNotificationsIndexes = [
        'swimmer_notifications_created_at_idx' => "ALTER TABLE swimmer_notifications ADD KEY swimmer_notifications_created_at_idx (created_at)",
        'swimmer_notifications_branch_idx' => "ALTER TABLE swimmer_notifications ADD KEY swimmer_notifications_branch_idx (target_branch)",
        'swimmer_notifications_subscription_idx' => "ALTER TABLE swimmer_notifications ADD KEY swimmer_notifications_subscription_idx (target_subscription)",
        'swimmer_notifications_level_idx' => "ALTER TABLE swimmer_notifications ADD KEY swimmer_notifications_level_idx (target_level)",
    ];

    foreach ($swimmerNotificationsIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM swimmer_notifications WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس إشعارات السباحين: ' . $exception->getMessage());
            }
        }
    }

    $offersTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'offers'");
    $offersTableExists = $offersTableExistsStmt && $offersTableExistsStmt->rowCount() > 0;

    if (!$offersTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS offers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                offer_title VARCHAR(180) NOT NULL,
                offer_description TEXT NOT NULL,
                valid_from DATE NULL,
                valid_until DATE NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY offers_active_idx (is_active),
                KEY offers_dates_idx (valid_from, valid_until),
                KEY offers_created_at_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $offersColumns = [
        'offer_title' => "ALTER TABLE offers ADD COLUMN offer_title VARCHAR(180) NOT NULL AFTER id",
        'offer_description' => "ALTER TABLE offers ADD COLUMN offer_description TEXT NOT NULL AFTER offer_title",
        'valid_from' => "ALTER TABLE offers ADD COLUMN valid_from DATE NULL AFTER offer_description",
        'valid_until' => "ALTER TABLE offers ADD COLUMN valid_until DATE NULL AFTER valid_from",
        'is_active' => "ALTER TABLE offers ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER valid_until",
        'created_by_user_id' => "ALTER TABLE offers ADD COLUMN created_by_user_id INT NULL AFTER is_active",
        'created_at' => "ALTER TABLE offers ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE offers ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($offersColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM offers LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $offersIndexes = [
        'offers_active_idx' => "ALTER TABLE offers ADD KEY offers_active_idx (is_active)",
        'offers_dates_idx' => "ALTER TABLE offers ADD KEY offers_dates_idx (valid_from, valid_until)",
        'offers_created_at_idx' => "ALTER TABLE offers ADD KEY offers_created_at_idx (created_at)",
    ];

    foreach ($offersIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM offers WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس العروض: ' . $exception->getMessage());
            }
        }
    }

    $groupEvaluationsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'group_evaluations'");
    $groupEvaluationsTableExists = $groupEvaluationsTableExistsStmt && $groupEvaluationsTableExistsStmt->rowCount() > 0;

    if (!$groupEvaluationsTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS group_evaluations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                subscription_id INT NOT NULL,
                evaluation_month CHAR(7) NOT NULL,
                evaluation_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
                evaluation_notes TEXT NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY group_evaluations_unique_month (subscription_id, evaluation_month),
                KEY group_evaluations_month_idx (evaluation_month),
                KEY group_evaluations_score_idx (evaluation_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $groupEvaluationsColumns = [
        'subscription_id' => "ALTER TABLE group_evaluations ADD COLUMN subscription_id INT NOT NULL AFTER id",
        'evaluation_month' => "ALTER TABLE group_evaluations ADD COLUMN evaluation_month CHAR(7) NOT NULL AFTER subscription_id",
        'evaluation_score' => "ALTER TABLE group_evaluations ADD COLUMN evaluation_score TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER evaluation_month",
        'evaluation_notes' => "ALTER TABLE group_evaluations ADD COLUMN evaluation_notes TEXT NULL AFTER evaluation_score",
        'created_by_user_id' => "ALTER TABLE group_evaluations ADD COLUMN created_by_user_id INT NULL AFTER evaluation_notes",
        'created_at' => "ALTER TABLE group_evaluations ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE group_evaluations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($groupEvaluationsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM group_evaluations LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $groupEvaluationsIndexes = [
        'group_evaluations_unique_month' => "ALTER TABLE group_evaluations ADD UNIQUE KEY group_evaluations_unique_month (subscription_id, evaluation_month)",
        'group_evaluations_month_idx' => "ALTER TABLE group_evaluations ADD KEY group_evaluations_month_idx (evaluation_month)",
        'group_evaluations_score_idx' => "ALTER TABLE group_evaluations ADD KEY group_evaluations_score_idx (evaluation_score)",
    ];

    foreach ($groupEvaluationsIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM group_evaluations WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس تقييم المجموعات: ' . $exception->getMessage());
            }
        }
    }

    $coachNotificationsTableExistsStmt = $pdo->query("SHOW TABLES LIKE 'coach_notifications'");
    $coachNotificationsTableExists = $coachNotificationsTableExistsStmt && $coachNotificationsTableExistsStmt->rowCount() > 0;

    if (!$coachNotificationsTableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS coach_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_message TEXT NOT NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY coach_notifications_created_at_idx (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    $coachNotificationsColumns = [
        'notification_message' => "ALTER TABLE coach_notifications ADD COLUMN notification_message TEXT NOT NULL AFTER id",
        'created_by_user_id' => "ALTER TABLE coach_notifications ADD COLUMN created_by_user_id INT NULL AFTER notification_message",
        'created_at' => "ALTER TABLE coach_notifications ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by_user_id",
        'updated_at' => "ALTER TABLE coach_notifications ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($coachNotificationsColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM coach_notifications LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $coachNotificationsIndexes = [
        'coach_notifications_created_at_idx' => "ALTER TABLE coach_notifications ADD KEY coach_notifications_created_at_idx (created_at)",
    ];

    foreach ($coachNotificationsIndexes as $indexName => $alterSql) {
        $indexExistsStmt = $pdo->query("SHOW INDEX FROM coach_notifications WHERE Key_name = " . $pdo->quote($indexName));
        if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $exception) {
                error_log('Unable to create coach notifications index: ' . $exception->getMessage());
            }
        }
    }

    if ($subscriptionsTableExists) {
        $legacyTrainingSessionsColumnStmt = $pdo->prepare("SHOW COLUMNS FROM subscriptions LIKE 'training_sessions_count'");
        $legacyTrainingSessionsColumnStmt->execute();
        $availableExercisesColumnStmt = $pdo->prepare("SHOW COLUMNS FROM subscriptions LIKE 'available_exercises_count'");
        $availableExercisesColumnStmt->execute();

        if (
            $legacyTrainingSessionsColumnStmt->rowCount() > 0
            && $availableExercisesColumnStmt->rowCount() === 0
        ) {
            $pdo->exec(
                "ALTER TABLE subscriptions CHANGE COLUMN training_sessions_count available_exercises_count INT NOT NULL DEFAULT 1 AFTER training_days_count"
            );
        }

        $subscriptionsColumns = [
            'subscription_name' => "ALTER TABLE subscriptions ADD COLUMN subscription_name VARCHAR(150) NOT NULL AFTER id",
            'subscription_branch' => "ALTER TABLE subscriptions ADD COLUMN subscription_branch VARCHAR(150) NOT NULL DEFAULT '' AFTER subscription_name",
            'subscription_category' => "ALTER TABLE subscriptions ADD COLUMN subscription_category VARCHAR(150) NOT NULL AFTER subscription_branch",
            'training_days_count' => "ALTER TABLE subscriptions ADD COLUMN training_days_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER subscription_category",
            'available_exercises_count' => "ALTER TABLE subscriptions ADD COLUMN available_exercises_count INT NOT NULL DEFAULT 1 AFTER training_days_count",
            'training_schedule' => "ALTER TABLE subscriptions ADD COLUMN training_schedule LONGTEXT NOT NULL AFTER available_exercises_count",
            'coach_id' => "ALTER TABLE subscriptions ADD COLUMN coach_id INT NOT NULL AFTER training_schedule",
            'max_trainees' => "ALTER TABLE subscriptions ADD COLUMN max_trainees INT NOT NULL DEFAULT 1 AFTER coach_id",
            'subscription_price' => "ALTER TABLE subscriptions ADD COLUMN subscription_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER max_trainees",
            'created_at' => "ALTER TABLE subscriptions ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER subscription_price",
            'updated_at' => "ALTER TABLE subscriptions ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($subscriptionsColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM subscriptions LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $subscriptionsIndexes = [
            'subscriptions_coach_idx' => "ALTER TABLE subscriptions ADD KEY subscriptions_coach_idx (coach_id)",
            'subscriptions_category_idx' => "ALTER TABLE subscriptions ADD KEY subscriptions_category_idx (subscription_category)",
        ];

        foreach ($subscriptionsIndexes as $indexName => $alterSql) {
            $indexExistsStmt = $pdo->query("SHOW INDEX FROM subscriptions WHERE Key_name = " . $pdo->quote($indexName));
            if (!$indexExistsStmt || $indexExistsStmt->rowCount() === 0) {
                try {
                    $pdo->exec($alterSql);
                } catch (PDOException $exception) {
                    error_log(sprintf('تعذر إنشاء فهرس الاشتراكات: %s - %s', $indexName, $exception->getMessage()));
                }
            }
        }

        $subscriptionsCreateTableStmt = $pdo->query("SHOW CREATE TABLE subscriptions");
        $subscriptionsCreateTable = $subscriptionsCreateTableStmt ? (string) ($subscriptionsCreateTableStmt->fetch(PDO::FETCH_ASSOC)['Create Table'] ?? '') : '';

        if (strpos($subscriptionsCreateTable, 'CONSTRAINT `subscriptions_training_days_count_chk` CHECK') === false) {
            try {
                $pdo->exec("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_training_days_count_chk CHECK (training_days_count BETWEEN 1 AND 7)");
            } catch (PDOException $exception) {
                error_log(sprintf('تعذر إنشاء قيد عدد أيام الاشتراكات: %s', $exception->getMessage()));
            }
        }

        if (strpos($subscriptionsCreateTable, 'CONSTRAINT `subscriptions_coach_fk` FOREIGN KEY') === false) {
            try {
                $pdo->exec("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_coach_fk FOREIGN KEY (coach_id) REFERENCES coaches(id) ON DELETE RESTRICT ON UPDATE CASCADE");
            } catch (PDOException $exception) {
                error_log(sprintf('تعذر إنشاء الربط بين الاشتراكات والمدربين: %s', $exception->getMessage()));
            }
        }
    }

    if ($coachAdvancesTableExists) {
        $coachAdvancesColumns = [
            'coach_id' => "ALTER TABLE coach_advances ADD COLUMN coach_id INT NOT NULL AFTER id",
            'advance_date' => "ALTER TABLE coach_advances ADD COLUMN advance_date DATE NOT NULL AFTER coach_id",
            'amount' => "ALTER TABLE coach_advances ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER advance_date",
            'created_at' => "ALTER TABLE coach_advances ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER amount",
            'updated_at' => "ALTER TABLE coach_advances ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($coachAdvancesColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM coach_advances LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $coachAdvancesDateIndex = $pdo->query("SHOW INDEX FROM coach_advances WHERE Key_name = 'coach_advances_date_idx'");
        if (!$coachAdvancesDateIndex || $coachAdvancesDateIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_advances ADD KEY coach_advances_date_idx (advance_date)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس تاريخ سلف المدربين');
            }
        }

        $coachAdvancesCoachIndex = $pdo->query("SHOW INDEX FROM coach_advances WHERE Key_name = 'coach_advances_coach_idx'");
        if (!$coachAdvancesCoachIndex || $coachAdvancesCoachIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_advances ADD KEY coach_advances_coach_idx (coach_id)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس المدرب في سلف المدربين');
            }
        }
    }

    $adminAdvancesColumns = [
        'administrator_id' => "ALTER TABLE admin_advances ADD COLUMN administrator_id INT NOT NULL AFTER id",
        'advance_date' => "ALTER TABLE admin_advances ADD COLUMN advance_date DATE NOT NULL AFTER administrator_id",
        'amount' => "ALTER TABLE admin_advances ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER advance_date",
        'created_at' => "ALTER TABLE admin_advances ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER amount",
        'updated_at' => "ALTER TABLE admin_advances ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($adminAdvancesColumns as $columnName => $alterSql) {
        $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM admin_advances LIKE ?");
        $columnExistsStmt->execute([$columnName]);

        if ($columnExistsStmt->rowCount() === 0) {
            $pdo->exec($alterSql);
        }
    }

    $adminAdvancesDateIndex = $pdo->query("SHOW INDEX FROM admin_advances WHERE Key_name = 'admin_advances_date_idx'");
    if (!$adminAdvancesDateIndex || $adminAdvancesDateIndex->rowCount() === 0) {
        try {
            $pdo->exec("ALTER TABLE admin_advances ADD KEY admin_advances_date_idx (advance_date)");
        } catch (PDOException $exception) {
            error_log('تعذر إنشاء فهرس تاريخ سلف الإداريين: ' . $exception->getMessage());
        }
    }

    $adminAdvancesAdministratorIndex = $pdo->query("SHOW INDEX FROM admin_advances WHERE Key_name = 'admin_advances_administrator_idx'");
    if (!$adminAdvancesAdministratorIndex || $adminAdvancesAdministratorIndex->rowCount() === 0) {
        try {
            $pdo->exec("ALTER TABLE admin_advances ADD KEY admin_advances_administrator_idx (administrator_id)");
        } catch (PDOException $exception) {
            error_log('تعذر إنشاء فهرس الإداري في سلف الإداريين: ' . $exception->getMessage());
        }
    }

    if ($coachSalaryPaymentsTableExists) {
        $coachSalaryPaymentsColumns = [
            'coach_id' => "ALTER TABLE coach_salary_payments ADD COLUMN coach_id INT NOT NULL AFTER id",
            'payment_cycle' => "ALTER TABLE coach_salary_payments ADD COLUMN payment_cycle ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly' AFTER coach_id",
            'period_start' => "ALTER TABLE coach_salary_payments ADD COLUMN period_start DATE NULL AFTER payment_cycle",
            'period_end' => "ALTER TABLE coach_salary_payments ADD COLUMN period_end DATE NULL AFTER period_start",
            'total_hours' => "ALTER TABLE coach_salary_payments ADD COLUMN total_hours DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER period_end",
            'hourly_rate' => "ALTER TABLE coach_salary_payments ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours",
            'gross_amount' => "ALTER TABLE coach_salary_payments ADD COLUMN gross_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER hourly_rate",
            'total_advances' => "ALTER TABLE coach_salary_payments ADD COLUMN total_advances DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER gross_amount",
            'net_amount' => "ALTER TABLE coach_salary_payments ADD COLUMN net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_advances",
            'attendance_days' => "ALTER TABLE coach_salary_payments ADD COLUMN attendance_days INT NOT NULL DEFAULT 0 AFTER net_amount",
            'advance_records_count' => "ALTER TABLE coach_salary_payments ADD COLUMN advance_records_count INT NOT NULL DEFAULT 0 AFTER attendance_days",
            'payment_status' => "ALTER TABLE coach_salary_payments ADD COLUMN payment_status ENUM('pending','paid') NOT NULL DEFAULT 'paid' AFTER advance_records_count",
            'reserved_at' => "ALTER TABLE coach_salary_payments ADD COLUMN reserved_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER payment_status",
            'paid_by_user_id' => "ALTER TABLE coach_salary_payments ADD COLUMN paid_by_user_id INT NULL AFTER reserved_at",
            'attendance_snapshot' => "ALTER TABLE coach_salary_payments ADD COLUMN attendance_snapshot LONGTEXT NULL AFTER paid_by_user_id",
            'advances_snapshot' => "ALTER TABLE coach_salary_payments ADD COLUMN advances_snapshot LONGTEXT NULL AFTER attendance_snapshot",
            'paid_at' => "ALTER TABLE coach_salary_payments ADD COLUMN paid_at TIMESTAMP NULL DEFAULT NULL AFTER advances_snapshot",
        ];

        foreach ($coachSalaryPaymentsColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM coach_salary_payments LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $coachSalaryPaymentsCoachIndex = $pdo->query("SHOW INDEX FROM coach_salary_payments WHERE Key_name = 'coach_salary_payments_coach_idx'");
        if (!$coachSalaryPaymentsCoachIndex || $coachSalaryPaymentsCoachIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_salary_payments ADD KEY coach_salary_payments_coach_idx (coach_id)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس المدرب في سجل قبض الرواتب');
            }
        }

        $coachSalaryPaymentsPaidAtIndex = $pdo->query("SHOW INDEX FROM coach_salary_payments WHERE Key_name = 'coach_salary_payments_paid_at_idx'");
        if (!$coachSalaryPaymentsPaidAtIndex || $coachSalaryPaymentsPaidAtIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE coach_salary_payments ADD KEY coach_salary_payments_paid_at_idx (paid_at)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس توقيت قبض الرواتب');
            }
        }

        $coachSalaryPaymentsPaidAtColumnStmt = $pdo->prepare("SHOW COLUMNS FROM coach_salary_payments LIKE 'paid_at'");
        $coachSalaryPaymentsPaidAtColumnStmt->execute();
        $coachSalaryPaymentsPaidAtColumn = $coachSalaryPaymentsPaidAtColumnStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (
            $coachSalaryPaymentsPaidAtColumn !== null
            && (
                strtoupper((string) ($coachSalaryPaymentsPaidAtColumn['Null'] ?? 'NO')) !== 'YES'
                || !array_key_exists('Default', $coachSalaryPaymentsPaidAtColumn)
                || $coachSalaryPaymentsPaidAtColumn['Default'] !== null
            )
        ) {
            try {
                $pdo->exec("ALTER TABLE coach_salary_payments MODIFY COLUMN paid_at TIMESTAMP NULL DEFAULT NULL");
            } catch (PDOException $exception) {
                error_log('تعذر تحديث حقل توقيت صرف رواتب المدربين: ' . $exception->getMessage());
            }
        }

        $coachSalaryPaymentsReservedAtColumnStmt = $pdo->prepare("SHOW COLUMNS FROM coach_salary_payments LIKE 'reserved_at'");
        $coachSalaryPaymentsReservedAtColumnStmt->execute();
        $coachSalaryPaymentsReservedAtColumn = $coachSalaryPaymentsReservedAtColumnStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (
            $coachSalaryPaymentsReservedAtColumn !== null
            && strtoupper((string) ($coachSalaryPaymentsReservedAtColumn['Null'] ?? 'NO')) !== 'YES'
        ) {
            try {
                $pdo->exec("ALTER TABLE coach_salary_payments MODIFY COLUMN reserved_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
            } catch (PDOException $exception) {
                error_log('تعذر تحديث حقل توقيت حجز رواتب المدربين: ' . $exception->getMessage());
            }
        }
    }

    if ($adminSalaryPaymentsTableExists) {
        $adminSalaryPaymentsColumns = [
            'administrator_id' => "ALTER TABLE admin_salary_payments ADD COLUMN administrator_id INT NOT NULL AFTER id",
            'payment_cycle' => "ALTER TABLE admin_salary_payments ADD COLUMN payment_cycle ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly' AFTER administrator_id",
            'period_start' => "ALTER TABLE admin_salary_payments ADD COLUMN period_start DATE NULL AFTER payment_cycle",
            'period_end' => "ALTER TABLE admin_salary_payments ADD COLUMN period_end DATE NULL AFTER period_start",
            'total_hours' => "ALTER TABLE admin_salary_payments ADD COLUMN total_hours DECIMAL(7,2) NOT NULL DEFAULT 0.00 AFTER period_end",
            'salary_amount' => "ALTER TABLE admin_salary_payments ADD COLUMN salary_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_hours",
            'total_advances' => "ALTER TABLE admin_salary_payments ADD COLUMN total_advances DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER salary_amount",
            'net_amount' => "ALTER TABLE admin_salary_payments ADD COLUMN net_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_advances",
            'attendance_days' => "ALTER TABLE admin_salary_payments ADD COLUMN attendance_days INT NOT NULL DEFAULT 0 AFTER net_amount",
            'advance_records_count' => "ALTER TABLE admin_salary_payments ADD COLUMN advance_records_count INT NOT NULL DEFAULT 0 AFTER attendance_days",
            'paid_by_user_id' => "ALTER TABLE admin_salary_payments ADD COLUMN paid_by_user_id INT NULL AFTER advance_records_count",
            'attendance_snapshot' => "ALTER TABLE admin_salary_payments ADD COLUMN attendance_snapshot LONGTEXT NULL AFTER paid_by_user_id",
            'advances_snapshot' => "ALTER TABLE admin_salary_payments ADD COLUMN advances_snapshot LONGTEXT NULL AFTER attendance_snapshot",
            'paid_at' => "ALTER TABLE admin_salary_payments ADD COLUMN paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER advances_snapshot",
        ];

        foreach ($adminSalaryPaymentsColumns as $columnName => $alterSql) {
            $columnExistsStmt = $pdo->prepare("SHOW COLUMNS FROM admin_salary_payments LIKE ?");
            $columnExistsStmt->execute([$columnName]);

            if ($columnExistsStmt->rowCount() === 0) {
                $pdo->exec($alterSql);
            }
        }

        $adminSalaryPaymentsAdministratorIndex = $pdo->query("SHOW INDEX FROM admin_salary_payments WHERE Key_name = 'admin_salary_payments_administrator_idx'");
        if (!$adminSalaryPaymentsAdministratorIndex || $adminSalaryPaymentsAdministratorIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE admin_salary_payments ADD KEY admin_salary_payments_administrator_idx (administrator_id)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس الإداري في سجل قبض المرتبات');
            }
        }

        $adminSalaryPaymentsPaidAtIndex = $pdo->query("SHOW INDEX FROM admin_salary_payments WHERE Key_name = 'admin_salary_payments_paid_at_idx'");
        if (!$adminSalaryPaymentsPaidAtIndex || $adminSalaryPaymentsPaidAtIndex->rowCount() === 0) {
            try {
                $pdo->exec("ALTER TABLE admin_salary_payments ADD KEY admin_salary_payments_paid_at_idx (paid_at)");
            } catch (PDOException $exception) {
                error_log('تعذر إنشاء فهرس توقيت قبض مرتبات الإداريين');
            }
        }
    }

} catch (PDOException $e) {
    die("فشل الاتصال بقاعدة البيانات: " . $e->getMessage());
}
