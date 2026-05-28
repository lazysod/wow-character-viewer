<?php
// Set config as global variable to be used in class
global $config;
$config = require_once './app/config.php';

class BlizzardTalentTest
{
    private $clientId;
    private $clientSecret;
    private $region;
    private $accessToken;
    private $iconCache = [];
    private $talentNameCache = [];
    private $gearIconCache = [];
    private $tierBonusCache = [];
    private $cachePath = __DIR__ . '/tmp/talent_icons.json';
    private $talentCachePath = __DIR__ . '/tmp/talent_names.json';
    private $gearIconCachePath = __DIR__ . '/tmp/gear_icons.json';
    private $tierBonusCachePath = __DIR__ . '/tmp/tier_bonuses.json';

    public function __construct()
    {
        global $config;
        $configData = is_array($config) ? $config : [];
        $this->clientId = $configData['blizzard']['client_id'] ?? '';
        $this->clientSecret = $configData['blizzard']['client_secret'] ?? '';
        $this->region = $configData['blizzard']['region'] ?? 'eu';
        $this->accessToken = $this->getAccessToken();

        foreach (
            [
                'iconCache' => $this->cachePath,
                'talentNameCache' => $this->talentCachePath,
                'gearIconCache' => $this->gearIconCachePath,
                'tierBonusCache' => $this->tierBonusCachePath
            ] as $prop => $path
        ) {
            if (file_exists($path)) {
                $this->$prop = json_decode(file_get_contents($path), true) ?? [];
            }
        }
    }

    public function __destruct()
    {
        foreach (
            [
                'iconCache' => $this->cachePath,
                'talentNameCache' => $this->talentCachePath,
                'gearIconCache' => $this->gearIconCachePath,
                'tierBonusCache' => $this->tierBonusCachePath
            ] as $prop => $path
        ) {
            if (!empty($this->$prop)) {
                $result = file_put_contents($path, json_encode($this->$prop, JSON_PRETTY_PRINT));
                if ($result === false) {
                    error_log("Cache write failed: {$path}");
                }
            }
        }
    }
    private function saveCaches()
    {
        foreach (
            [
                'iconCache' => $this->cachePath,
                'talentNameCache' => $this->talentCachePath,
                'gearIconCache' => $this->gearIconCachePath,
                'tierBonusCache' => $this->tierBonusCachePath
            ] as $prop => $path
        ) {
            if (!empty($this->$prop)) {
                $dir = dirname($path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $result = file_put_contents($path, json_encode($this->$prop, JSON_PRETTY_PRINT));
                if ($result === false) {
                    die("FAILED TO WRITE CACHE: {$path} - Check permissions");
                }
            }
        }
    }
    private function apiRequest($url, $params = [])
    {
        $ch = curl_init();
        if (!empty($params)) {
            $separator = strpos($url, '?') === false ? '?' : '&';
            $url .= $separator . http_build_query($params);
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$this->accessToken}"]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new Exception("API Error: HTTP $httpCode");
        }
        return json_decode($response, true);
    }

    private function getAccessToken()
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://{$this->region}.battle.net/oauth/token",
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->clientId}:{$this->clientSecret}",
            CURLOPT_POSTFIELDS => "grant_type=client_credentials"
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new Exception("Failed to get access token");
        }
        return $data['access_token'];
    }

    private function getTalentName($talentId)
    {
        if (isset($this->talentNameCache[$talentId])) {
            return $this->talentNameCache[$talentId];
        }
        try {
            $url = "https://{$this->region}.api.blizzard.com/data/wow/talent/{$talentId}";
            $data = $this->apiRequest($url, ['namespace' => "static-{$this->region}"]);
            $name = $data['name'] ?? null;
            if ($name) {
                $this->talentNameCache[$talentId] = $name;
                return $name;
            }
        } catch (Exception $e) {
            // don't cache, don't log spam
        }
        return null; // Return null, not 'Unknown Talent'
    }

    private function getSpellIcon($spellUrl, $spellId)
    {
        if (isset($this->iconCache[$spellId])) {
            return $this->iconCache[$spellId];
        }
        try {
            $spellData = $this->apiRequest($spellUrl);
            if (!isset($spellData['media']['key']['href'])) {
                $this->iconCache[$spellId] = null;
                return null;
            }
            $mediaData = $this->apiRequest($spellData['media']['key']['href']);
            foreach ($mediaData['assets'] as $asset) {
                if ($asset['key'] === 'icon') {
                    $this->iconCache[$spellId] = $asset['value'];
                    return $asset['value'];
                }
            }
        } catch (Exception $e) {
            $this->iconCache[$spellId] = null;
        }
        return null;
    }

    private function getGearIcon($itemId, $mediaUrl)
    {
        if (isset($this->gearIconCache[$itemId])) {
            return $this->gearIconCache[$itemId];
        }
        try {
            $mediaData = $this->apiRequest($mediaUrl);
            foreach ($mediaData['assets'] as $asset) {
                if ($asset['key'] === 'icon') {
                    $this->gearIconCache[$itemId] = $asset['value'];
                    return $asset['value'];
                }
            }
        } catch (Exception $e) { /* ignore */
        }
        $this->gearIconCache[$itemId] = null;
        return null;
    }

    private function getTierBonuses($setId)
    {
        if (isset($this->tierBonusCache[$setId])) {
            return $this->tierBonusCache[$setId];
        }
        try {
            $url = "https://{$this->region}.api.blizzard.com/data/wow/item-set/{$setId}";
            $data = $this->apiRequest($url, ['namespace' => "static-{$this->region}", 'locale' => 'en_US']);
            $bonuses = [];
            foreach ($data['effects'] as $effect) {
                $bonuses[$effect['required_count']] = strip_tags($effect['display_string']);
            }
            $this->tierBonusCache[$setId] = $bonuses;
            return $bonuses;
        } catch (Exception $e) {
            $this->tierBonusCache[$setId] = [];
            return [];
        }
    }

    private function processTalents($talents)
    {
        if (empty($talents)) return [];

        $result = [];
        foreach ($talents as $talent) {
            $talentId = $talent['id'] ?? null;
            $rank = $talent['rank'] ?? 0;

            // Priority 1: Name from spell_tooltip - this exists for 90% of talents
            $name = $talent['tooltip']['spell_tooltip']['spell']['name'] ?? null;

            // Priority 2: Name from talent tooltip
            if (!$name) {
                $name = $talent['tooltip']['talent']['name'] ?? null;
            }

            $spellId = $talent['tooltip']['spell_tooltip']['spell']['id'] ?? null;
            $spellUrl = $talent['tooltip']['spell_tooltip']['spell']['key']['href'] ?? null;

            // Priority 3: Only hit the static API if we still have nothing
            if (!$name && $talentId) {
                $name = $this->getTalentName($talentId); // returns null if 404
            }

            // Final fallback
            if (!$name) {
                $name = $talentId ? "Talent #{$talentId}" : 'Unknown';
            }

            $iconUrl = null;
            if ($spellUrl && $spellId) {
                $iconUrl = $this->getSpellIcon($spellUrl, $spellId);
            }

            $result[] = [
                'name' => $name,
                'rank' => $rank,
                'icon' => $iconUrl,
                'spell_id' => $spellId,
                'talent_id' => $talentId
            ];
        }
        return $result;
    }

    public function getCharacterTalents($realmSlug, $characterName)
    {
        $realmSlug = strtolower(str_replace(' ', '-', $realmSlug));
        $characterName = strtolower($characterName);
        $baseUrl = "https://{$this->region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$characterName}";

        $charData = $this->apiRequest($baseUrl, ['namespace' => "profile-{$this->region}", 'locale' => 'en_US']);

        // This is the key change: we need the full tree data
        $specData = $this->apiRequest("{$baseUrl}/specializations", [
            'namespace' => "profile-{$this->region}",
            'locale' => 'en_US' // <-- This forces tooltip data
        ]);

        if (empty($specData['specializations'])) {
            throw new Exception("No specialization data found");
        }

        $spec = $specData['specializations'][0];
        if (empty($spec['loadouts'])) {
            throw new Exception("No loadouts found");
        }

        $loadout = null;
        foreach ($spec['loadouts'] as $l) {
            if (!empty($l['is_active'])) {
                $loadout = $l;
                break;
            }
        }
        if (!$loadout) $loadout = $spec['loadouts'][0];

        $heroTree = $spec['selected_hero_talent_tree']['name'] ??
            $loadout['selected_hero_talent_tree']['name'] ??
            'None';

        $classTalents = $this->processTalents($loadout['selected_class_talents'] ?? []);
        $specTalents = $this->processTalents($loadout['selected_spec_talents'] ?? []);
        $heroTalents = $this->processTalents($loadout['selected_hero_talents'] ?? []);

        // Merge duplicates inside each list + remove cross-list doubles
        function cleanTalents($talents, &$seenKeys)
        {
            $out = [];
            foreach ($talents as $t) {
                if (empty($t['name']) || $t['name'] === 'Unknown' || strpos($t['name'], 'Talent #') === 0) continue;

                $key = $t['spell_id'] ?? ($t['talent_id'] ? 't' . $t['talent_id'] : $t['name']);

                if (isset($seenKeys[$key])) {
                    // Already seen - add ranks together if it's a dupe in same list
                    foreach ($out as &$existing) {
                        $existingKey = $existing['spell_id'] ?? 't' . $existing['talent_id'] ?? $existing['name'];
                        if ($existingKey === $key) {
                            $existing['rank'] += $t['rank'];
                            break;
                        }
                    }
                } else {
                    $seenKeys[$key] = true;
                    $out[] = $t;
                }
            }
            return $out;
        }

        $seen = [];
        $classTalents = cleanTalents($classTalents, $seen);
        $specTalents = cleanTalents($specTalents, $seen);
        $heroTalents = cleanTalents($heroTalents, $seen);

        $this->saveCaches();

        return [
            'character' => [
                'name' => $charData['name'],
                'realm' => $charData['realm']['name'],
                'level' => $charData['level'],
                'race' => $charData['race']['name'],
                'class' => $charData['character_class']['name'],
                'spec' => $charData['active_spec']['name'],
                'hero_tree' => $heroTree,
                'faction' => $charData['faction']['type']
            ],
            'talents' => [
                'class' => $classTalents,
                'spec' => $specTalents,
                'hero' => $heroTalents
            ],
            'loadout_code' => $loadout['talent_loadout_code'] ?? null
        ];
    }

    public function getCharacterEquipment($realmSlug, $characterName)
    {
        $realmSlug = strtolower(str_replace(' ', '-', $realmSlug));
        $characterName = strtolower($characterName);
        $baseUrl = "https://{$this->region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$characterName}";
        $equipData = $this->apiRequest("{$baseUrl}/equipment", ['namespace' => "profile-{$this->region}", 'locale' => 'en_US']);

        $items = [];
        $tierPieces = [];
        $tierBonuses = [];
        $ilvlTotal = 0;
        $ilvlCount = 0;

        // Upgrade summary counters
        $upgradeableItems = 0;
        $totalCrestsNeeded = 0;
        $crestsByTrack = [];

        foreach ($equipData['equipped_items'] as $item) {
            $iconUrl = null;
            if (isset($item['media']['key']['href'])) {
                $iconUrl = $this->getGearIcon($item['item']['id'], $item['media']['key']['href']);
            }

            // Upgrade track - use API fields directly
            $upgrade = null;
            $upgradeTrack = null;
            $upgradeCurrent = 0;
            $upgradeMax = 0;
            $upgradeRemaining = 0;

            if (isset($item['upgrade'])) {
                $upgradeTrack = $item['upgrade']['name'] ?? null;
                $upgradeCurrent = $item['upgrade']['level']['current'] ?? 0;
                $upgradeMax = $item['upgrade']['level']['max'] ?? 0;

                if ($upgradeTrack && $upgradeMax > 0) {
                    $upgradeRemaining = $upgradeMax - $upgradeCurrent;
                    $upgrade = "{$upgradeCurrent}/{$upgradeMax} {$upgradeTrack}";

                    // Add to summary if upgrades remaining
                    if ($upgradeRemaining > 0) {
                        $upgradeableItems++;
                        $totalCrestsNeeded += $upgradeRemaining;
                        $crestsByTrack[$upgradeTrack] = ($crestsByTrack[$upgradeTrack] ?? 0) + $upgradeRemaining;
                    }
                }
            }

            // Tier set detection + bonus text
            $setName = null;
            $setId = null;
            if (!empty($item['set'])) {
                $setName = $item['set']['item_set']['name'];
                $setId = $item['set']['item_set']['id'];
                $tierPieces[$setName] = ($tierPieces[$setName] ?? 0) + 1;
                if ($setId && empty($tierBonuses[$setId])) {
                    $tierBonuses[$setId] = $this->getTierBonuses($setId);
                }
            }

            // Manual ilvl average - skip shirt/tabard
            if (!in_array($item['slot']['type'], ['SHIRT', 'TABARD']) && $item['level']['value'] > 1) {
                $ilvlTotal += $item['level']['value'];
                $ilvlCount++;
            }

            // Missing enchant/gem warnings
            $enchantSlots = ['CHEST', 'LEGS', 'FEET', 'FINGER_1', 'FINGER_2', 'MAIN_HAND', 'OFF_HAND', 'WRIST', 'BACK'];
            $socketSlots = ['HEAD', 'NECK', 'WRIST', 'WAIST', 'FINGER_1', 'FINGER_2'];
            $missingEnchant = in_array($item['slot']['type'], $enchantSlots) && empty($item['enchantments']);
            $missingGem = in_array($item['slot']['type'], $socketSlots) && !empty($item['sockets']) && empty(array_filter($item['sockets'], fn($s) => !empty($s['item'])));

            $items[] = [
                'id' => $item['item']['id'],
                'slot' => $item['slot']['name'],
                'slot_type' => $item['slot']['type'],
                'name' => $item['name'],
                'ilvl' => $item['level']['value'],
                'quality' => $item['quality']['type'],
                'icon' => $iconUrl,
                'enchant' => $item['enchantments'][0]['display_string'] ?? null,
                'sockets' => array_map(fn($s) => $s['item']['name'] ?? $s['display_string'] ?? null, $item['sockets'] ?? []),
                'transmog' => $item['transmog']['item']['name'] ?? null,
                'upgrade' => $upgrade,
                'upgrade_track' => $upgradeTrack,
                'upgrade_current' => $upgradeCurrent,
                'upgrade_max' => $upgradeMax,
                'upgrade_remaining' => $upgradeRemaining,
                'set_name' => $setName,
                'set_id' => $setId,
                'missing_enchant' => $missingEnchant,
                'missing_gem' => $missingGem,
                'bonus_list' => $item['bonus_list'] ?? []
            ];
        }

        $equippedIlvl = $equipData['equipped_item_level']
            ?? $equipData['average_item_level']
            ?? ($ilvlCount ? round($ilvlTotal / $ilvlCount) : 0);

        return [
            'items' => $items,
            'equipped_ilvl' => $equippedIlvl,
            'tier_pieces' => $tierPieces,
            'tier_bonuses' => $tierBonuses,
            'upgrade_summary' => [
                'upgradeable_items' => $upgradeableItems,
                'total_crests_needed' => $totalCrestsNeeded,
                'crests_by_track' => $crestsByTrack // e.g. ['Hero' => 6, 'Myth' => 3]
            ]
        ];
    }

    public function getMythicPlus($realmSlug, $characterName)
    {
        try {
            $realmSlug = strtolower(str_replace(' ', '-', $realmSlug));
            $characterName = strtolower($characterName);
            $baseUrl = "https://{$this->region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$characterName}/mythic-keystone-profile";
            $data = $this->apiRequest($baseUrl, ['namespace' => "profile-{$this->region}", 'locale' => 'en_US']);
            return [
                'rating' => $data['current_mythic_rating']['rating'] ?? 0,
                'color' => $data['current_mythic_rating']['color'] ?? null,
                'runs' => array_slice($data['best_runs'] ?? [], 0, 3)
            ];
        } catch (Exception $e) {
            return ['rating' => 0, 'color' => null, 'runs' => []];
        }
    }

    public function getCharacterStats($realmSlug, $characterName)
    {
        try {
            $realmSlug = strtolower(str_replace(' ', '-', $realmSlug));
            $characterName = strtolower($characterName);
            $baseUrl = "https://{$this->region}.api.blizzard.com/profile/wow/character/{$realmSlug}/{$characterName}/statistics";
            $data = $this->apiRequest($baseUrl, ['namespace' => "profile-{$this->region}", 'locale' => 'en_US']);
            return [
                'health' => $data['health'] ?? 0,
                'strength' => $data['strength']['effective'] ?? 0,
                'agility' => $data['agility']['effective'] ?? 0,
                'intellect' => $data['intellect']['effective'] ?? 0,
                'stamina' => $data['stamina']['effective'] ?? 0,
                'crit' => round($data['melee_crit']['value'] ?? 0, 2),
                'haste' => round($data['melee_haste']['value'] ?? 0, 2),
                'mastery' => round($data['mastery']['value'] ?? 0, 2),
                'versatility' => round($data['versatility_damage_done_bonus'] ?? 0, 2)
            ];
        } catch (Exception $e) {
            return null;
        }
    }

    public function generateSimcString($charData, $gearData, $talentData)
    {
        $c = $charData['character'];

        $specMap = [
            'Beast Mastery' => 'beast_mastery',
            'Marksmanship' => 'marksmanship',
            'Survival' => 'survival',
            'Arcane' => 'arcane',
            'Fire' => 'fire',
            'Frost' => 'frost',
            'Discipline' => 'discipline',
            'Holy' => 'holy',
            'Shadow' => 'shadow',
            'Assassination' => 'assassination',
            'Outlaw' => 'outlaw',
            'Subtlety' => 'subtlety',
            'Elemental' => 'elemental',
            'Enhancement' => 'enhancement',
            'Restoration' => 'restoration',
            'Blood' => 'blood',
            'Unholy' => 'unholy',
            'Arms' => 'arms',
            'Fury' => 'fury',
            'Protection' => 'protection',
            'Balance' => 'balance',
            'Feral' => 'feral',
            'Guardian' => 'guardian',
            'Brewmaster' => 'brewmaster',
            'Mistweaver' => 'mistweaver',
            'Windwalker' => 'windwalker',
            'Devastation' => 'devastation',
            'Preservation' => 'preservation',
            'Augmentation' => 'augmentation',
            'Havoc' => 'havoc',
            'Vengeance' => 'vengeance',
            'Affliction' => 'affliction',
            'Demonology' => 'demonology',
            'Destruction' => 'destruction',
            'Retribution' => 'retribution'
        ];

        $race = strtolower(str_replace(' ', '_', $c['race']));
        $spec = $specMap[$c['spec']] ?? strtolower(str_replace(' ', '_', $c['spec']));
        $class = strtolower(str_replace(' ', '_', $c['class']));

        $simc = "{$class}=" . preg_replace('/[^A-Za-z0-9]/', '', $c['name']) . "\n";
        $simc .= "spec={$spec}\n";
        $simc .= "race={$race}\n\n";

        $slotMap = [
            'HEAD' => 'head',
            'NECK' => 'neck',
            'SHOULDER' => 'shoulder',
            'BACK' => 'back',
            'CHEST' => 'chest',
            'WRIST' => 'wrist',
            'HANDS' => 'hands',
            'WAIST' => 'waist',
            'LEGS' => 'legs',
            'FEET' => 'feet',
            'FINGER_1' => 'finger1',
            'FINGER_2' => 'finger2',
            'TRINKET_1' => 'trinket1',
            'TRINKET_2' => 'trinket2',
            'MAIN_HAND' => 'main_hand',
            'OFF_HAND' => 'off_hand'
        ];

        foreach ($gearData['items'] as $item) {
            if (empty($item['id']) || $item['id'] == 0) continue;
            if (empty($item['ilvl']) || $item['ilvl'] == 0) continue;

            $slot = $slotMap[$item['slot_type']] ?? strtolower($item['slot_type']);
            if (in_array($slot, ['shirt', 'tabard'])) continue;

            $parts = ["id={$item['id']}", "ilevel={$item['ilvl']}"];

            if (!empty($item['bonus_list']) && is_array($item['bonus_list'])) {
                $bonusIds = array_filter($item['bonus_list'], fn($b) => $b > 0);
                if (!empty($bonusIds)) {
                    $parts[] = "bonus_id=" . implode('/', $bonusIds);
                }
            }

            $simc .= "{$slot}=," . implode(',', $parts) . "\n";
        }

        if (!empty($talentData['loadout_code'])) {
            $simc .= "\ntalents=" . strtok($talentData['loadout_code'], '?') . "\n";
        }

        return $simc;
    }
}

function ilvlColorClass($ilvl)
{
    if ($ilvl >= 480) return 'text-danger';
    if ($ilvl >= 450) return 'text-warning';
    if ($ilvl >= 415) return 'text-info';
    if ($ilvl >= 385) return 'text-success';
    return 'text-light';
}

function mplusColor($rating)
{
    if ($rating >= 3000) return '#ff8000';
    if ($rating >= 2500) return '#a335ee';
    if ($rating >= 2000) return '#0070dd';
    if ($rating >= 1500) return '#1eff00';
    return '#ffffff';
}
?>
<!doctype html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WoW Talent Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script>
        const whTooltips = {
            colorLinks: true,
            iconizeLinks: false, // set this to false
            renameLinks: true
        };
    </script>
    <script src="https://wow.zamimg.com/js/tooltips.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@600&display=swap');

        body {
            background: #0a0a0a url('./images/bg.jpeg') repeat;
            background-size: cover;
            background-attachment: fixed;
            font-family: 'Segoe UI', sans-serif;
        }

        .wow-header {
            font-family: 'Cinzel', serif;
            color: #ffd100;
            text-shadow: 2px 2px 4px #000;
        }

        .talent-icon {
            border: 1px solid #ffd100;
            border-radius: 3px;
            box-shadow: 0 0 6px rgba(255, 209, 0, 0.5);
        }

        .nav-tabs.nav-link.active {
            background: #1a1a1a;
            border-color: #ffd100;
            color: #ffd100;
        }

        .nav-tabs.nav-link {
            color: #c7b377;
        }

        .card {
            background: rgba(20, 20, 20, 0.9);
            border: 1px solid #444;
        }

        .talent-row {
            padding: 6px 0;
            border-bottom: 1px solid #333;
        }

        .talent-row:last-child {
            border-bottom: none;
        }

        .quality-poor {
            color: #9d9d9d;
        }

        .quality-common {
            color: #ffffff;
        }

        .quality-uncommon {
            color: #1eff00;
        }

        .quality-rare {
            color: #0070dd;
        }

        .quality-epic {
            color: #a335ee;
        }

        .quality-legendary {
            color: #ff8000;
        }

        .quality-artifact {
            color: #e6cc80;
        }

        button.nav-link {
            background-color: black;
            margin-right: 1px;
        }

        button#spec-tab:hover,
        button#class-tab:hover,
        button#hero-tab:hover,
        button#gear-tab:hover,
        button#compare-tab:hover {
            background-color: #565656;
        }

        .tier-bonus-tooltip {
            cursor: help;
            text-decoration: underline dotted;
        }

        .warning-icon {
            color: #ff4444;
            font-weight: bold;
        }
    </style>
    <?php
    $ogTitle = "WoW Talent & Gear Viewer";
    $ogDesc = "Instantly view WoW character talents, gear, M+ score, tier bonuses, and more. Powered by Blizzard's official API.";
    $ogImage = "https://albaweb.net/wow/images/thumbnail.png";

    if (!empty($_POST['character']) && !empty($_POST['realm'])) {
        $ogTitle = htmlspecialchars($_POST['character']) . " - " . htmlspecialchars($_POST['realm']);
        $ogDesc = "View talents, gear, and M+ score for " . htmlspecialchars($_POST['character']);
    }
    ?>
    <meta property="og:title" content="<?php echo $ogTitle; ?>">
    <meta property="og:description" content="<?php echo $ogDesc; ?>">
    <meta property="twitter:title" content="<?php echo $ogTitle; ?>">
    <meta property="twitter:description" content="<?php echo $ogDesc; ?>">
    <meta property="og:image" content="<?php echo $ogImage; ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="https://albaweb.net/wow/">
    <meta property="twitter:title" content="<?php echo $ogTitle; ?>">
    <meta property="twitter:description" content="<?php echo $ogDesc; ?>">
    <meta property="twitter:image" content="<?php echo $ogImage; ?>">

    <!-- Theme color for mobile browsers -->
    <meta name="theme-color" content="#ffd100">
</head>

<body>

    <div class="container py-4">
        <h1 class="wow-header text-center mb-4">Talent & Gear Viewer</h1>

        <form method="post" class="mb-4" id="charForm">
            <div class="row g-2">
                <div class="col-md-4">
                    <input type="text" class="form-control" name="character"
                        placeholder="Character name"
                        value="<?php echo htmlspecialchars($_POST['character'] ?? ''); ?>" required>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control" name="realm"
                        placeholder="Realm"
                        value="<?php echo htmlspecialchars($_POST['realm'] ?? 'Darkmoon Faire'); ?>" required>
                </div>
                <div class="col-md-3">
                    <input type="text" class="form-control" name="compare_character"
                        placeholder="Compare: Name-Realm (optional)"
                        value="<?php echo htmlspecialchars($_POST['compare_character'] ?? ''); ?>">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-warning w-100" type="submit">Fetch</button>
                </div>
            </div>
        </form>

        <?php
        if (!empty($_POST['character']) && !empty($_POST['realm'])) {
            $character = strip_tags($_POST['character']);
            $realm = strip_tags($_POST['realm']);
            try {
                $tester = new BlizzardTalentTest();
                $data = $tester->getCharacterTalents($realm, $character);
                $gear = $tester->getCharacterEquipment($realm, $character);
                $mplus = $tester->getMythicPlus($realm, $character);
                $stats = $tester->getCharacterStats($realm, $character);
                $c = $data['character'];

                // Compare character
                $compareData = null;
                $compareGear = null;
                $compareMplus = null;
                $compareStats = null;
                if (!empty($_POST['compare_character']) && strpos($_POST['compare_character'], '-') !== false) {
                    list($compChar, $compRealm) = explode('-', $_POST['compare_character'], 2);
                    try {
                        $compareData = $tester->getCharacterTalents(trim($compRealm), trim($compChar));
                        $compareGear = $tester->getCharacterEquipment(trim($compRealm), trim($compChar));
                        $compareMplus = $tester->getMythicPlus(trim($compRealm), trim($compChar));
                        $compareStats = $tester->getCharacterStats(trim($compRealm), trim($compChar));
                    } catch (Exception $e) {
                        echo "<div class='alert alert-warning'>Compare character error: " . htmlspecialchars($e->getMessage()) . "</div>";
                    }
                }
        ?>

                <div class="row">
                    <div class="col-md-<?php echo $compareData ? '6' : '12'; ?>">
                        <div class="card mb-4">
                            <div class="card-body">
                                <h3 class="wow-header"><?php echo htmlspecialchars($c['name']); ?> - <?php echo htmlspecialchars($c['realm']); ?></h3>
                                <p class="mb-1">Level <?php echo $c['level']; ?> <?php echo htmlspecialchars($c['race']); ?> <?php echo htmlspecialchars($c['class']); ?></p>
                                <p class="mb-1">Active Spec: <span class="text-warning"><?php echo htmlspecialchars($c['spec']); ?></span></p>
                                <p class="mb-1">Hero Tree: <span class="text-warning"><?php echo htmlspecialchars($c['hero_tree']); ?></span></p>
                                <p class="mb-1">Item Level: <span class="text-warning <?php echo ilvlColorClass($gear['equipped_ilvl']); ?>"><?php echo $gear['equipped_ilvl'] ?: 'Unequipped'; ?></span></p>
                                <?php if ($mplus['rating'] > 0): ?>
                                    <p class="mb-1">M+ Score: <span style="color: <?php echo mplusColor($mplus['rating']); ?>; font-weight: bold;"><?php echo $mplus['rating']; ?></span></p>
                                <?php endif; ?>
                                <?php if ($stats): ?>
                                    <p class="mb-1 small">Stats:
                                        <span class="text-info">Crit <?php echo $stats['crit']; ?>%</span> |
                                        <span class="text-success">Haste <?php echo $stats['haste']; ?>%</span> |
                                        <span class="text-warning">Mastery <?php echo $stats['mastery']; ?>%</span> |
                                        <span class="text-primary">Vers <?php echo $stats['versatility']; ?>%</span>
                                    </p>
                                <?php endif; ?>
                                <?php if ($data['loadout_code']): ?>
                                    <div class="mt-2">
                                        <a href="https://www.wowhead.com/talent-calc/blizzard/<?php echo $data['loadout_code']; ?>"
                                            target="_blank" class="btn btn-sm btn-outline-warning">View on Wowhead</a>
                                        <!-- SimC export disabled - Raidbots keeps changing format 
                                            <button class="btn btn-sm btn-outline-success" onclick="copySimC()">Copy SimC</button> -->
                                    </div>
                                    <textarea id="simcString" class="d-none"><?php echo htmlspecialchars($tester->generateSimcString($data, $gear, $data)); ?></textarea>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($compareData): ?>
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-body">
                                    <h3 class="wow-header"><?php echo htmlspecialchars($compareData['character']['name']); ?> - <?php echo htmlspecialchars($compareData['character']['realm']); ?></h3>
                                    <p class="mb-1">Level <?php echo $compareData['character']['level']; ?> <?php echo htmlspecialchars($compareData['character']['race']); ?> <?php echo htmlspecialchars($compareData['character']['class']); ?></p>
                                    <p class="mb-1">Active Spec: <span class="text-warning"><?php echo htmlspecialchars($compareData['character']['spec']); ?></span></p>
                                    <p class="mb-1">Hero Tree: <span class="text-warning"><?php echo htmlspecialchars($compareData['character']['hero_tree']); ?></span></p>
                                    <p class="mb-0">Item Level: <span class="text-warning <?php echo ilvlColorClass($compareGear['equipped_ilvl']); ?>"><?php echo $compareGear['equipped_ilvl'] ?: 'Unequipped'; ?></span></p>
                                    <?php if ($compareMplus['rating'] > 0): ?>
                                        <p class="mb-0">M+ Score: <span style="color: <?php echo mplusColor($compareMplus['rating']); ?>; font-weight: bold;"><?php echo $compareMplus['rating']; ?></span></p>
                                    <?php endif; ?>
                                    <?php if ($compareStats): ?>
                                        <p class="mb-0 small">Stats:
                                            <span class="text-info">Crit <?php echo $compareStats['crit']; ?>%</span> |
                                            <span class="text-success">Haste <?php echo $compareStats['haste']; ?>%</span> |
                                            <span class="text-warning">Mastery <?php echo $compareStats['mastery']; ?>%</span> |
                                            <span class="text-primary">Vers <?php echo $compareStats['versatility']; ?>%</span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <ul class="nav nav-tabs" id="talentTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="class-tab" data-bs-toggle="tab" data-bs-target="#class-pane" type="button">
                            Class <span class="badge bg-secondary"><?php echo count($data['talents']['class']); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="spec-tab" data-bs-toggle="tab" data-bs-target="#spec-pane" type="button">
                            Spec <span class="badge bg-secondary"><?php echo count($data['talents']['spec']); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="hero-tab" data-bs-toggle="tab" data-bs-target="#hero-pane" type="button">
                            Hero <span class="badge bg-secondary"><?php echo count($data['talents']['hero']); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="gear-tab" data-bs-toggle="tab" data-bs-target="#gear-pane" type="button">
                            Gear <span class="badge bg-secondary"><?php echo $gear['equipped_ilvl'] ?: count($gear['items']); ?></span>
                        </button>
                    </li>
                    <?php if ($compareData): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="compare-tab" data-bs-toggle="tab" data-bs-target="#compare-pane" type="button">
                                Compare
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>

                <div class="tab-content" id="talentTabsContent">
                    <?php foreach (['class', 'spec', 'hero'] as $idx => $type): ?>
                        <div class="tab-pane fade <?php echo $idx === 0 ? 'show active' : ''; ?>" id="<?php echo $type; ?>-pane">
                            <div class="card card-body">
                                <?php foreach ($data['talents'][$type] as $talent): ?>
                                    <div class="talent-row d-flex align-items-center">
                                        <?php if ($talent['icon']): ?>
                                            <img src="<?php echo htmlspecialchars($talent['icon']); ?>"
                                                class="talent-icon me-2" width="24" height="24" alt="">
                                        <?php endif; ?>
                                        <span class="flex-grow-1">
                                            <?php if ($talent['spell_id']): ?>
                                                <a href="https://www.wowhead.com/spell=<?php echo $talent['spell_id']; ?>"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    data-wowhead="spell=<?php echo $talent['spell_id']; ?>">
                                                    <?php echo htmlspecialchars($talent['name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($talent['name']); ?>
                                            <?php endif; ?>
                                        </span>
                                        <span class="badge bg-dark">Rank <?php echo $talent['rank']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div class="tab-pane fade" id="gear-pane">
                        <div class="card card-body">
                            <!-- ADD THIS BLOCK HERE -->
                            <?php if ($gear['upgrade_summary']['upgradeable_items'] > 0): ?>
                                <div class="alert alert-info mb-3">
                                    <strong>Upgrades available:</strong>
                                    <?php echo $gear['upgrade_summary']['upgradeable_items']; ?> items,
                                    <?php echo $gear['upgrade_summary']['total_crests_needed']; ?> crests needed
                                    <?php if (!empty($gear['upgrade_summary']['crests_by_track'])): ?>
                                        <div class="small mt-1">
                                            <?php foreach ($gear['upgrade_summary']['crests_by_track'] as $track => $count): ?>
                                                <span class="badge bg-secondary me-1"><?php echo $count; ?>x <?php echo htmlspecialchars($track); ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary mb-3">
                                    <strong>No upgradeable items equipped.</strong>
                                    Tier pieces and raid drops don't use crest upgrades.
                                </div>
                            <?php endif; ?>
                            <!-- END BLOCK -->
                            <?php if (!empty($gear['tier_pieces'])): ?>
                                <div class="mb-3 pb-2 border-bottom border-secondary">
                                    <?php foreach ($gear['tier_pieces'] as $setName => $count): ?>
                                        <?php
                                        $setId = null;
                                        foreach ($gear['items'] as $i) {
                                            if ($i['set_name'] === $setName) {
                                                $setId = $i['set_id'];
                                                break;
                                            }
                                        }
                                        $bonusText = '';
                                        if ($setId && isset($gear['tier_bonuses'][$setId])) {
                                            $b = $gear['tier_bonuses'][$setId];
                                            $bonusText = "2pc: " . ($b[2] ?? 'N/A') . "\n4pc: " . ($b[4] ?? 'N/A');
                                        }
                                        ?>
                                        <div class="d-flex align-items-center">
                                            <span class="badge <?php echo $count >= 4 ? 'bg-success' : 'bg-warning'; ?> me-2"><?php echo $count; ?>/4</span>
                                            <span class="text-warning tier-bonus-tooltip" title="<?php echo htmlspecialchars($bonusText); ?>">
                                                <?php echo htmlspecialchars($setName); ?>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php
                            $slotOrder = ['HEAD', 'NECK', 'SHOULDER', 'BACK', 'CHEST', 'WRIST', 'HANDS', 'WAIST', 'LEGS', 'FEET', 'FINGER_1', 'FINGER_2', 'TRINKET_1', 'TRINKET_2', 'MAIN_HAND', 'OFF_HAND', 'TABARD'];
                            $sortedItems = [];
                            foreach ($gear['items'] as $item) {
                                $sortedItems[$item['slot_type']] = $item;
                            }
                            foreach ($slotOrder as $slot):
                                if (!isset($sortedItems[$slot])) continue;
                                $item = $sortedItems[$slot];
                                $wowheadUrl = "https://www.wowhead.com/item=" . $item['id'];
                            ?>
                                <div class="talent-row d-flex align-items-start">
                                    <div class="me-2" style="width:100px; font-size:0.85rem; color:#999;">
                                        <?php echo htmlspecialchars($item['slot']); ?>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center">
                                            <?php if ($item['icon']): ?>
                                                <img src="<?php echo htmlspecialchars($item['icon']); ?>"
                                                    class="talent-icon me-2" width="24" height="24" alt="">
                                            <?php endif; ?>
                                            <a href="<?php echo $wowheadUrl; ?>"
                                                target="_blank"
                                                rel="noreferrer"
                                                data-wowhead="item=<?php echo $item['id']; ?>"
                                                class="quality-<?php echo strtolower($item['quality']); ?>">
                                                <?php echo htmlspecialchars($item['name']); ?>
                                            </a>
                                            <span class="badge bg-dark ms-2 <?php echo ilvlColorClass($item['ilvl']); ?>"><?php echo $item['ilvl']; ?></span>
                                            <?php if ($item['upgrade']): ?>
                                                <span class="badge bg-secondary ms-1">
                                                    <?php echo htmlspecialchars($item['upgrade']); ?>
                                                    <?php if ($item['upgrade_remaining'] > 0): ?>
                                                        <span class="text-warning">(<?php echo $item['upgrade_remaining']; ?> left)</span>
                                                    <?php else: ?>
                                                        <span class="text-success">Max</span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($item['missing_enchant']): ?>
                                                <span class="warning-icon ms-2" title="Missing enchant">⚠️</span>
                                            <?php endif; ?>
                                            <?php if ($item['missing_gem']): ?>
                                                <span class="warning-icon ms-1" title="Empty socket">💎</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($item['enchant']): ?>
                                            <div class="text-success" style="font-size:0.8rem;"><?php echo htmlspecialchars($item['enchant']); ?></div>
                                        <?php endif; ?>
                                        <?php foreach ($item['sockets'] as $gem): ?>
                                            <?php if ($gem): ?>
                                                <div class="text-info" style="font-size:0.8rem;"><?php echo htmlspecialchars($gem); ?></div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if ($item['transmog']): ?>
                                            <div class="text-muted" style="font-size:0.75rem;">Transmog: <?php echo htmlspecialchars($item['transmog']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ($compareData): ?>
                        <div class="tab-pane fade" id="compare-pane">
                            <div class="card card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5 class="wow-header"><?php echo htmlspecialchars($c['name']); ?></h5>
                                        <table class="table table-sm table-dark">
                                            <tr>
                                                <td>Item Level</td>
                                                <td class="<?php echo ilvlColorClass($gear['equipped_ilvl']); ?>"><?php echo $gear['equipped_ilvl']; ?></td>
                                            </tr>
                                            <tr>
                                                <td>M+ Score</td>
                                                <td style="color: <?php echo mplusColor($mplus['rating']); ?>"><?php echo $mplus['rating']; ?></td>
                                            </tr>
                                            <?php if ($stats): ?>
                                                <tr>
                                                    <td>Crit</td>
                                                    <td><?php echo $stats['crit']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Haste</td>
                                                    <td><?php echo $stats['haste']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Mastery</td>
                                                    <td><?php echo $stats['mastery']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Vers</td>
                                                    <td><?php echo $stats['versatility']; ?>%</td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td>Tier Pieces</td>
                                                <td><?php echo array_sum($gear['tier_pieces']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <h5 class="wow-header"><?php echo htmlspecialchars($compareData['character']['name']); ?></h5>
                                        <table class="table table-sm table-dark">
                                            <tr>
                                                <td>Item Level</td>
                                                <td class="<?php echo ilvlColorClass($compareGear['equipped_ilvl']); ?>"><?php echo $compareGear['equipped_ilvl']; ?></td>
                                            </tr>
                                            <tr>
                                                <td>M+ Score</td>
                                                <td style="color: <?php echo mplusColor($compareMplus['rating']); ?>"><?php echo $compareMplus['rating']; ?></td>
                                            </tr>
                                            <?php if ($compareStats): ?>
                                                <tr>
                                                    <td>Crit</td>
                                                    <td><?php echo $compareStats['crit']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Haste</td>
                                                    <td><?php echo $compareStats['haste']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Mastery</td>
                                                    <td><?php echo $compareStats['mastery']; ?>%</td>
                                                </tr>
                                                <tr>
                                                    <td>Vers</td>
                                                    <td><?php echo $compareStats['versatility']; ?>%</td>
                                                </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <td>Tier Pieces</td>
                                                <td><?php echo array_sum($compareGear['tier_pieces']); ?></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                                <hr>
                                <h6 class="text-warning">Talent Differences</h6>
                                <?php
                                $mainTalents = [];
                                foreach (['class', 'spec', 'hero'] as $t) {
                                    foreach ($data['talents'][$t] as $tal) {
                                        $mainTalents[$tal['talent_id']] = $tal;
                                    }
                                }
                                $compTalents = [];
                                foreach (['class', 'spec', 'hero'] as $t) {
                                    foreach ($compareData['talents'][$t] as $tal) {
                                        $compTalents[$tal['talent_id']] = $tal;
                                    }
                                }
                                $allIds = array_unique(array_merge(array_keys($mainTalents), array_keys($compTalents)));
                                foreach ($allIds as $id) {
                                    $m = $mainTalents[$id] ?? null;
                                    $c = $compTalents[$id] ?? null;
                                    if ($m['rank'] ?? 0 != $c['rank'] ?? 0) {
                                        echo '<div class="d-flex justify-content-between small border-bottom border-secondary py-1">';
                                        echo '<span>' . htmlspecialchars($m['name'] ?? $c['name']) . '</span>';
                                        echo '<span><span class="badge bg-primary">' . ($m['rank'] ?? 0) . '</span> vs <span class="badge bg-danger">' . ($c['rank'] ?? 0) . '</span></span>';
                                        echo '</div>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

        <?php
            } catch (Exception $e) {
                echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
        ?>
    </div>

    <!-- Loading overlay -->
    <div id="loadingOverlay" class="d-none position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-75" style="z-index:9999;">
        <div class="d-flex justify-content-center align-items-center h-100">
            <div class="text-center">
                <div class="spinner-border text-warning" style="width: 3rem; height: 3rem;" role="status"></div>
                <div class="wow-header mt-3">Summoning character data...</div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('charForm').addEventListener('submit', function() {
            document.getElementById('loadingOverlay').classList.remove('d-none');
            this.querySelector('button[type="submit"]').disabled = true;
            this.querySelector('button[type="submit"]').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Loading...';
        });

        function copySimC() {
            const simc = document.getElementById('simcString').value;
            navigator.clipboard.writeText(simc).then(() => {
                event.target.textContent = 'Copied!';
                setTimeout(() => event.target.textContent = 'Copy SimC', 2000);
            });
        }

        // Initialize Bootstrap tooltips for tier bonuses
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>

</html>