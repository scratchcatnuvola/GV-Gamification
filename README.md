# ⚠️ READ THIS FIRST — WHICH REPO IS THIS?

> ## 🔴 **THIS REPOSITORY IS ONLY FOR THE GAMIFICATION LIBRARY (XP, LEVELS, ACHIEVEMENTS)**
> ### **This is NOT the place to open issues or pull requests for the Game Vault website.**
> ### **👉 For bug reports, game requests and pull requests about the site, go to: [github.com/scratchcatnuvola/GameVault](https://github.com/scratchcatnuvola/GameVault)**

---

# 🎮 GV-Gamification

A tiny, framework-agnostic PHP engine for **XP**, **unlimited levels** and **achievements**.
It is the same engine that powers [Game Vault](https://github.com/scratchcatnuvola/GameVault), extracted into a single zero-dependency file you can drop into any PHP project.

**The Game Vault website itself is closed source. This library is the only public part of its backend.**

## ✨ Features

- **Unlimited levels** — no cap, the curve grows forever: `XP to reach level n = 100 × (n−1) × n ÷ 2`
- **One file, zero dependencies** — plain PHP 7.4+, works with MySQL / MariaDB (any PDO driver)
- **Auto-installing schema** — `install()` creates its three tables if missing
- **Achievements engine** — define your own achievements with plain PHP conditions
- **Battle-tested** — it runs the real XP system of Game Vault

## 📦 Installation

1. Download `Gamification.php`
2. Put it next to your code and include it:

```php
require __DIR__ . '/Gamification.php';
```

## 🚀 Quick start

```php
$gam = new GVGamification(new PDO('mysql\:host=localhost;dbname=myapp;charset=utf8mb4', 'user', 'pass'));
$gam->install(); // create tables (safe to call every time)

// Grant XP (a review, an upload, anything): returns the NEW level
$newLevel = $gam->addXp($userId, 25);

// Full level card for your UI
$card = $gam->getLevel($userId);
// ['xp' => 1250, 'level' => 5, 'progress' => 37, 'xp_for_next' => 1500, ...]

echo "Level {$card['level']} — {$card['progress']}% to the next one";
```

## 🏆 Achievements

Define achievements with a code, title, description, icon, XP reward and a condition over your own stats:

```php
$gam->setAchievements([
    ['first_upload', 'First Upload', 'You uploaded your first file', '📤', 100,
        function (array $s) { return $s['uploads'] >= 1; }],
    ['veteran', 'Veteran', 'Reached level 50', '🎖️', 1000, null], // manual unlock
]);

// After any user action, re-check everything against the user's stats:
$newlyUnlocked = $gam->recheck($userId, ['uploads' => 1]);
// -> ['first_upload']

// Or unlock manually:
$gam->unlock($userId, 'veteran');

// Show the trophy cabinet:
foreach ($gam->unlocked($userId) as $a) {
    echo "{$a['icon']} {$a['title']} (+{$a['xp_reward']} XP)";
}
```

## 📈 Level curve

| Level | Total XP required |
|------:|-----------------:|
| 2     | 100              |
| 3     | 300              |
| 5     | 1,000            |
| 10    | 4,500            |
| 50    | 122,500          |
| 100   | 495,000          |
| 149   | 1,102,600        |
| …     | no cap, ever     |

## 📄 License

MIT — free for any use. If you build something cool with it, a mention is appreciated but not required.

---

### 🔗 Related

- **Game Vault — issues, bug reports and pull requests for the website:** [scratchcatnuvola/GameVault](https://github.com/scratchcatnuvola/GameVault)
