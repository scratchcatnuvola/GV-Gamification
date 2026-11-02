<?php
declare(strict_types=1);

/**
 * GV Gamification
 * ---------------
 * A tiny, framework-agnostic PHP engine for XP, unlimited levels and
 * achievements. Extracted from Game Vault.
 *
 * The Game Vault website itself is CLOSED SOURCE. Bug reports, feature
 * requests and pull requests for the website belong to the separate
 * repository: https://github.com/scratchcatnuvola/GameVault
 *
 * License: MIT
 */

final class GVGamification
{
    /** @var PDO */
    private $db;

    /** @var array Achievement definitions: [code, title, description, icon, xpReward, condition] */
    private $achievements = [];

    /**
     * @param PDO $db Any PDO connection (MySQL, SQLite, ...).
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /* ================= LEVEL CURVE ================= */

    /**
     * Total XP needed to REACH a level: 100 * (n-1) * n / 2.
     * Level 1 = 0 XP, level 2 = 100, level 3 = 300, level 149 = 1,102,600 ...
     * There is NO level cap.
     */
    public static function xpForLevel(int $level): int
    {
        if ($level < 1) $level = 1;
        return (int)(100 * ($level - 1) * $level / 2);
    }

    /**
     * The level for a given total XP. Levels are unlimited.
     */
    public static function levelFromXp(int $xp): int
    {
        $level = 1;
        while ($xp >= self::xpForLevel($level + 1)) $level++;
        return $level;
    }

    /* ================= SCHEMA ================= */

    /**
     * Creates the three tables if they do not exist. Idempotent.
     */
    public function install(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS gv_xp (
            user_id INT UNSIGNED PRIMARY KEY,
            xp BIGINT UNSIGNED NOT NULL DEFAULT 0,
            level INT UNSIGNED NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS gv_achievements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL UNIQUE,
            title VARCHAR(190) NOT NULL,
            description VARCHAR(500) NOT NULL DEFAULT "",
            icon VARCHAR(16) NOT NULL DEFAULT "",
            xp_reward INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->exec('CREATE TABLE IF NOT EXISTS gv_user_achievements (
            user_id INT UNSIGNED NOT NULL,
            achievement_id INT UNSIGNED NOT NULL,
            unlocked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, achievement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    /* ================= XP ================= */

    /**
     * Grants XP to a user and returns the NEW level after the grant.
     */
    public function addXp(int $userId, int $amount): int
    {
        if ($amount === 0) return self::levelFromXp($this->getXp($userId));
        $st = $this->db->prepare('INSERT INTO gv_xp (user_id, xp, level) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE xp = xp + VALUES(xp)');
        $st->execute([$userId, max(0, $amount)]);
        $xp = $this->getXp($userId);
        $level = self::levelFromXp($xp);
        $this->db->prepare('UPDATE gv_xp SET level = ? WHERE user_id = ?')->execute([$level, $userId]);
        return $level;
    }

    /**
     * Total XP of a user (0 if unknown).
     */
    public function getXp(int $userId): int
    {
        $st = $this->db->prepare('SELECT xp FROM gv_xp WHERE user_id = ?');
        $st->execute([$userId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Full level card for UIs:
     * xp, level, progress (0-100), xp_for_next, xp_in_level, xp_needed.
     */
    public function getLevel(int $userId): array
    {
        $xp = $this->getXp($userId);
        $level = self::levelFromXp($xp);
        $nextXp = self::xpForLevel($level + 1);
        $prevXp = self::xpForLevel($level);
        $needed = max(1, $nextXp - $prevXp);
        return [
            'xp'          => $xp,
            'level'       => $level,
            'progress'    => min(100, max(0, (int)(($xp - $prevXp) / $needed * 100))),
            'xp_for_next' => $nextXp,
            'xp_in_level' => $xp - $prevXp,
            'xp_needed'   => $needed,
        ];
    }

    /* ================= ACHIEVEMENTS ================= */

    /**
     * Registers achievement definitions.
     * Each definition: [code, title, description, icon, xpReward, condition]
     * - code: unique string, e.g. "first_review"
     * - title / description: strings
     * - icon: any short string (emoji work great)
     * - xpReward: int
     * - condition: callable fn(array $stats): bool  (or null to unlock manually)
     */
    public function setAchievements(array $definitions): void
    {
        $this->achievements = $definitions;
    }

    /**
     * Unlocks one achievement for a user. Grants its XP reward.
     * Returns true only the FIRST time it is unlocked.
     */
    public function unlock(int $userId, string $code): bool
    {
        foreach ($this->achievements as $a) {
            if ($a[0] !== $code) continue;
            $st = $this->db->prepare('SELECT id FROM gv_achievements WHERE code = ?');
            $st->execute([$code]);
            $id = $st->fetchColumn();
            if (!$id) {
                $this->db->prepare('INSERT INTO gv_achievements (code, title, description, icon, xp_reward) VALUES (?,?,?,?,?)')
                    ->execute([$a[0], $a[1], $a[2], isset($a[3]) ? $a[3] : '', isset($a[4]) ? (int)$a[4] : 0]);
                $id = (int)$this->db->lastInsertId();
            }
            $st = $this->db->prepare('INSERT IGNORE INTO gv_user_achievements (user_id, achievement_id) VALUES (?,?)');
            $st->execute([$userId, (int)$id]);
            if ($st->rowCount() > 0) {
                $this->addXp($userId, isset($a[4]) ? (int)$a[4] : 0);
                return true;
            }
            return false;
        }
        return false;
    }

    /**
     * Whether a user already unlocked the given achievement.
     */
    public function has(int $userId, string $code): bool
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM gv_user_achievements ua
            JOIN gv_achievements a ON a.id = ua.achievement_id
            WHERE ua.user_id = ? AND a.code = ?');
        $st->execute([$userId, $code]);
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * Evaluates every condition against a stats array (yours to define,
     * e.g. ['reviews' => 3, 'uploads' => 0]) and unlocks whatever qualifies.
     * Returns the list of newly unlocked codes.
     */
    public function recheck(int $userId, array $stats): array
    {
        $unlocked = [];
        foreach ($this->achievements as $a) {
            $cond = isset($a[5]) ? $a[5] : null;
            if ($cond !== null && $cond($stats) && $this->unlock($userId, $a[0])) {
                $unlocked[] = $a[0];
            }
        }
        return $unlocked;
    }

    /**
     * All achievements unlocked by a user, oldest first.
     */
    public function unlocked(int $userId): array
    {
        $st = $this->db->prepare('SELECT a.code, a.title, a.description, a.icon, a.xp_reward, ua.unlocked_at
            FROM gv_user_achievements ua
            JOIN gv_achievements a ON a.id = ua.achievement_id
            WHERE ua.user_id = ?
            ORDER BY ua.unlocked_at');
        $st->execute([$userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
