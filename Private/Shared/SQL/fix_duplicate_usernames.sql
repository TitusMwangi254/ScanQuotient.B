-- Fix duplicate usernames in scanquotient.a1
-- Keeps the oldest account (lowest id) per username; renames newer duplicates.
-- Safe to re-run: only affects rows where duplicates still exist.

USE `scanquotient.a1`;

-- 1) Preview duplicates before changing anything
SELECT
    LOWER(TRIM(user_name)) AS username_key,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(id ORDER BY id) AS user_ids,
    GROUP_CONCAT(user_name ORDER BY id) AS usernames
FROM users
WHERE user_name IS NOT NULL AND TRIM(user_name) <> ''
GROUP BY LOWER(TRIM(user_name))
HAVING duplicate_count > 1
ORDER BY duplicate_count DESC, username_key;

-- 2) Rename duplicates (keep lowest id unchanged)
UPDATE users u
INNER JOIN (
    SELECT
        id,
        user_name,
        ROW_NUMBER() OVER (
            PARTITION BY LOWER(TRIM(user_name))
            ORDER BY id ASC
        ) AS row_num
    FROM users
    WHERE user_name IS NOT NULL AND TRIM(user_name) <> ''
) ranked ON u.id = ranked.id
SET
    u.user_name = CONCAT(
        LOWER(TRIM(ranked.user_name)),
        '_',
        SUBSTRING(MD5(CONCAT(u.id, u.user_id)), 1, 4)
    ),
    u.updated_at = NOW()
WHERE ranked.row_num > 1;

-- 3) Verify: should return zero rows
SELECT
    LOWER(TRIM(user_name)) AS username_key,
    COUNT(*) AS duplicate_count
FROM users
WHERE user_name IS NOT NULL AND TRIM(user_name) <> ''
GROUP BY LOWER(TRIM(user_name))
HAVING duplicate_count > 1;

-- 4) Prevent future duplicates (run once; ignore error if index exists)
-- ALTER TABLE users ADD UNIQUE KEY uniq_user_name (user_name);
