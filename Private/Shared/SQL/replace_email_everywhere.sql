-- Replace an email everywhere in scanquotient.a1
-- Run entire script in phpMyAdmin (fixes error #1267 collation mix).
-- Edit ONLY lines 7-8, then click Go.

USE `scanquotient.a1`;

SET @old_email = 'kelvinmwangindekere24@gmail.com';
SET @new_email = 'watergate@gmail.com';

SET @old_cmp = LOWER(CONVERT(@old_email USING utf8mb4) COLLATE utf8mb4_general_ci);
SET @new_cmp = LOWER(CONVERT(@new_email USING utf8mb4) COLLATE utf8mb4_general_ci);

-- PREVIEW
SELECT '=== USERS ===' AS section;
SELECT id, user_id, email, recovery_email FROM users
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp
   OR LOWER(recovery_email) COLLATE utf8mb4_general_ci = @old_cmp;

SELECT '=== PAYMENTS ===' AS section;
SELECT id, email, package, status FROM payments
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

SELECT '=== SUPPORT TICKETS ===' AS section;
SELECT unique_id, email, status FROM support_tickets
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

SELECT '=== CUSTOMER FEEDBACK ===' AS section;
SELECT id, email, subject FROM customer_feedback
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

-- UPDATE
START TRANSACTION;

UPDATE users SET email = @new_email, updated_at = NOW()
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

UPDATE users SET recovery_email = @new_email, updated_at = NOW()
WHERE LOWER(recovery_email) COLLATE utf8mb4_general_ci = @old_cmp;

UPDATE payments SET email = @new_email
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

UPDATE support_tickets SET email = @new_email
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

UPDATE customer_feedback SET email = @new_email
WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp;

UPDATE security_logs
SET description = REPLACE(
    description,
    CONVERT(@old_email USING utf8mb4) COLLATE utf8mb4_general_ci,
    CONVERT(@new_email USING utf8mb4) COLLATE utf8mb4_general_ci
)
WHERE INSTR(
    description,
    CONVERT(@old_email USING utf8mb4) COLLATE utf8mb4_general_ci
) > 0;

COMMIT;

-- VERIFY (old counts should all be 0)
SELECT '=== VERIFY ===' AS section;
SELECT 'users.email' AS location, COUNT(*) AS old_email_count
FROM users WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp
UNION ALL SELECT 'users.recovery_email', COUNT(*)
FROM users WHERE LOWER(recovery_email) COLLATE utf8mb4_general_ci = @old_cmp
UNION ALL SELECT 'payments', COUNT(*)
FROM payments WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp
UNION ALL SELECT 'support_tickets', COUNT(*)
FROM support_tickets WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp
UNION ALL SELECT 'customer_feedback', COUNT(*)
FROM customer_feedback WHERE LOWER(email) COLLATE utf8mb4_general_ci = @old_cmp
UNION ALL SELECT 'new email in users', COUNT(*)
FROM users WHERE LOWER(email) COLLATE utf8mb4_general_ci = @new_cmp;
