@extends('layouts.app')

@section('title', '뱀파이어 서바이벌 - Mini Game')

@section('body-class', 'vampire-survival-page')

@section('content')
    <div class="game-wrapper">
        <div class="game-header-bar">
            <a href="{{ route('mini-game.index') }}" class="back-button">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                돌아가기
            </a>
            <span class="game-title">🧛 뱀파이어 서바이벌</span>
        </div>

        {{-- 시작 화면 = 캐릭터 선택 --}}
        <div class="game-start-screen" id="startScreen">
            <div class="start-screen-content">
                <h2>🧛 뱀파이어 서바이벌</h2>
                <p>캐릭터를 선택하세요</p>
                <div class="vs-char-grid">
                    <button class="vs-char-card" data-char="rainy">
                        <div class="vs-char-portrait" style="background-image:url('/images/mini-game/vampire-survivors/Charactor_sprite2.png');background-size:384px 384px;background-position:0 0;"></div>
                        <div class="vs-char-name">레이니</div>
                        <div class="vs-char-weapon">메인: 🌂 우산</div>
                    </button>
                    <div class="vs-char-card locked">
                        <div class="vs-char-portrait vs-locked">?</div>
                        <div class="vs-char-name">준비중</div>
                        <div class="vs-char-weapon">Coming soon</div>
                    </div>
                    <div class="vs-char-card locked">
                        <div class="vs-char-portrait vs-locked">?</div>
                        <div class="vs-char-name">준비중</div>
                        <div class="vs-char-weapon">Coming soon</div>
                    </div>
                </div>
            </div>
        </div>

        <div id="game-container" style="display: none;" tabindex="0" title="터치하여 이동"></div>
    </div>

    <x-mini-game.ranking-overlay game="vampire-survival" />

    {{-- 레벨업 선택 오버레이 (능력치 / 서브무기) --}}
    <div id="vs-choice" class="vs-choice" hidden>
        <div class="vs-choice-box">
            <h3 id="vs-choice-title">레벨 업!</h3>
            <div id="vs-choice-cards" class="vs-choice-cards"></div>
        </div>
    </div>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/phaser@3.80.1/dist/phaser.min.js"></script>
    <script>
// ============================================================================
// 뱀파이어 서바이벌 — 캐릭터 선택 + 스프라이트 애니메이션 + 다중 무기 누적
// ============================================================================
const isMobile = () => 'ontouchstart' in window || window.innerWidth < 768;
const getGameSize = () => {
    if (isMobile()) {
        const w = Math.min(window.innerWidth, 800);
        const h = Math.min(window.innerHeight - 60, 600);
        return { width: Math.max(320, w), height: Math.max(400, h) };
    }
    return { width: 800, height: 600 };
};

const CONFIG = {
    PLAYER_SPEED: 215,
    PLAYER_HP: 120,
    ENEMY_BASE_SPEED: 66,
    ENEMY_BASE_HP: 18,
    ENEMY_DAMAGE: 7,
    XP_TO_LEVEL: 60,
    SPAWN_INTERVAL: 1700,
    MAX_ENEMIES: 38,
};

// 캐릭터 스프라이트 그리드 (Charactor_sprite2.png: 4열×4행, 셀 256×256, 투명 배경)
const SHEET = { cell: 256, cols: 4, rows: 4 };

// 캐릭터별 고유 메인 무기
const CHARACTERS = {
    rainy: { name: '레이니', main: 'umbrella' },
};

// 무기 정의 (main=항상 발동 메인 / sub=레벨10에 장착·강화)
const WEAPONS = {
    umbrella:   { name: '우산', kind: 'main', type: 'melee', cooldown: 520, damage: 22, range: 125 },
    knife:      { name: '칼',   kind: 'sub',  type: 'melee', cooldown: 430, damage: 22, range: 105, rangeStep: 38 },
    pistol:     { name: '총',   kind: 'sub',  type: 'gun',   cooldown: 520, damage: 20, bullets: 1, spread: 0.12, speed: 620 },
    shotgun:    { name: '샷건', kind: 'sub',  type: 'gun',   cooldown: 1000, damage: 12, bullets: 5, spread: 0.6, speed: 540 },
    machinegun: { name: '기관총', kind: 'sub', type: 'gun',  cooldown: 150, damage: 7, bullets: 1, spread: 0.22, speed: 720 },
};
const SUB_WEAPONS = ['pistol', 'shotgun', 'machinegun', 'knife'];
const BULLET_COLOR = { pistol: 0xfff176, shotgun: 0xffb74d, machinegun: 0x4dd0e1 };

class GameScene extends Phaser.Scene {
    constructor() { super({ key: 'GameScene' }); }

    init() {
        this.charKey = window.__vsChar || 'rainy';
        this.playerHP = CONFIG.PLAYER_HP;
        this.maxHP = CONFIG.PLAYER_HP;
        this.playerSpeed = CONFIG.PLAYER_SPEED;
        this.bonusAttack = 0;
        this.level = 1;
        this.xp = 0;
        this.xpToNext = CONFIG.XP_TO_LEVEL;
        this.kills = 0;
        this.gameTime = 0;
        this.isGameOver = false;
        this.paused = false;
        this.pendingChoices = [];
        this.facing = 1;

        // 장착 무기: 캐릭터 메인 무기부터 시작
        const main = CHARACTERS[this.charKey].main;
        this.equipped = {};
        this.equipped[main] = { level: 1, cd: 0 };
    }

    preload() {
        this.load.image('char2', '/images/mini-game/vampire-survivors/Charactor_sprite2.png');
    }

    create() {
        this.physics.world.setBounds(-1000, -1000, 3000, 3000);
        this.registerFrames();
        this.makeAnims();
        this.makeBulletTextures();

        this.createBackground();
        this.createPlayer();

        this.enemies = this.physics.add.group();
        this.xpOrbs = this.physics.add.group();
        this.bullets = this.physics.add.group();

        this.physics.add.overlap(this.player, this.enemies, this.onPlayerHit, null, this);
        this.physics.add.overlap(this.player, this.xpOrbs, this.collectXP, null, this);
        this.physics.add.overlap(this.bullets, this.enemies, this.onBulletHit, null, this);

        this.cursors = this.input.keyboard.createCursorKeys();
        this.wasd = this.input.keyboard.addKeys({ up: 'W', down: 'S', left: 'A', right: 'D' });
        this.touchMoveActive = false;
        this.input.on('pointerdown', () => { this.touchMoveActive = true; });
        this.input.on('pointerup', () => { this.touchMoveActive = false; });
        this.input.on('pointerout', () => { this.touchMoveActive = false; });

        this.createUI();

        this.refreshSpawnTimer();
        this.time.addEvent({ delay: 1000, callback: () => { if (!this.isGameOver && !this.paused) this.gameTime++; }, loop: true });

        this.cameras.main.startFollow(this.player, true, 0.1, 0.1);
    }

    // --- 스프라이트 프레임/애니메이션 ---
    registerFrames() {
        const tex = this.textures.get('char2');
        for (let r = 0; r < SHEET.rows; r++) {
            for (let c = 0; c < SHEET.cols; c++) {
                tex.add(`c2_${r}_${c}`, 0, c * SHEET.cell, r * SHEET.cell, SHEET.cell, SHEET.cell);
            }
        }
    }

    makeAnims() {
        const F = (r, c) => ({ key: 'char2', frame: `c2_${r}_${c}` });
        const def = (key, frames, fps, repeat = -1) => {
            if (!this.anims.exists(key)) this.anims.create({ key, frames, frameRate: fps, repeat });
        };
        // 이동 모션만 사용(공격 모션 없음). 새 스프라이트의 앞모습 걷기 프레임(0행) 사용.
        def('idle', [F(0, 0)], 1);
        def('walk', [F(0, 0), F(0, 1), F(0, 2), F(0, 3)], 8);
        def('death', [F(0, 0)], 1, 0);
    }

    makeBulletTextures() {
        for (const [key, color] of Object.entries(BULLET_COLOR)) {
            const tk = 'bullet_' + key;
            if (this.textures.exists(tk)) continue;
            const g = this.add.graphics();
            g.fillStyle(color); g.fillCircle(5, 5, 5);
            g.fillStyle(0xffffff, 0.7); g.fillCircle(4, 4, 2);
            g.generateTexture(tk, 10, 10); g.destroy();
        }
    }

    createBackground() {
        const graphics = this.add.graphics();
        const tileSize = 64;
        for (let x = -1000; x < 2000; x += tileSize) {
            for (let y = -1000; y < 2000; y += tileSize) {
                graphics.fillStyle(((x + y) / tileSize) % 2 === 0 ? 0x16213e : 0x1a1a2e);
                graphics.fillRect(x, y, tileSize, tileSize);
            }
        }
    }

    createPlayer() {
        const w = this.scale.width, h = this.scale.height;
        this.player = this.physics.add.sprite(w / 2, h / 2, 'char2', 'c2_0_0');
        this.player.setScale(0.34);
        this.player.setCollideWorldBounds(true);
        this.player.setDepth(10);
        this.player.body.setCircle(38, SHEET.cell / 2 - 38, SHEET.cell / 2 - 30);
        this.player.play('idle');
    }

    createUI() {
        this.uiContainer = this.add.container(0, 0).setScrollFactor(0).setDepth(100);

        const hpBarBg = this.add.graphics(); hpBarBg.fillStyle(0x333333); hpBarBg.fillRoundedRect(20, 20, 200, 25, 5);
        this.uiContainer.add(hpBarBg);
        this.hpBar = this.add.graphics(); this.uiContainer.add(this.hpBar); this.updateHPBar();

        const xpBarBg = this.add.graphics(); xpBarBg.fillStyle(0x333333); xpBarBg.fillRoundedRect(20, 50, 200, 15, 3);
        this.uiContainer.add(xpBarBg);
        this.xpBar = this.add.graphics(); this.uiContainer.add(this.xpBar); this.updateXPBar();

        const ts = { fontSize: '18px', fill: '#ffffff', stroke: '#000000', strokeThickness: 3 };
        this.levelText = this.add.text(20, 70, 'Lv. 1', ts); this.uiContainer.add(this.levelText);

        const gw = this.scale.width, gh = this.scale.height;
        this.timeText = this.add.text(gw - 100, 20, '00:00', ts); this.uiContainer.add(this.timeText);
        this.killText = this.add.text(gw - 100, 45, 'Kills: 0', ts); this.uiContainer.add(this.killText);
        this.weaponHudText = this.add.text(gw - 240, 72, '', { fontSize: '13px', fill: '#8ff0e0', stroke: '#000', strokeThickness: 2 });
        this.uiContainer.add(this.weaponHudText); this.updateWeaponHUD();

        const helpMsg = isMobile() ? '화면 터치로 이동 | 무기 자동 발동' : 'WASD / 방향키로 이동 | 무기 자동 발동';
        const helpText = this.add.text(gw / 2, gh - 30, helpMsg, { fontSize: '14px', fill: '#aaaaaa' }).setOrigin(0.5);
        this.uiContainer.add(helpText);
    }

    updateHPBar() {
        this.hpBar.clear();
        const p = Math.max(0, this.playerHP) / this.maxHP;
        const color = p > 0.5 ? 0x4ecca3 : (p > 0.25 ? 0xf9ed69 : 0xe94560);
        this.hpBar.fillStyle(color); this.hpBar.fillRoundedRect(20, 20, 200 * p, 25, 5);
    }

    updateXPBar() {
        this.xpBar.clear();
        this.xpBar.fillStyle(0x7b68ee);
        this.xpBar.fillRoundedRect(20, 50, 200 * (this.xp / this.xpToNext), 15, 3);
    }

    updateWeaponHUD() {
        if (!this.weaponHudText) return;
        this.weaponHudText.setText(Object.keys(this.equipped).map(k => `${WEAPONS[k].name}${this.equipped[k].level}`).join('  '));
    }

    // --- 적 ---
    // 레벨에 따라 늘어나는 값들 (적이 점점 많아지도록)
    maxEnemies() { return Math.min(CONFIG.MAX_ENEMIES + this.level * 4, 110); }
    spawnBatchSize() { return 1 + Math.floor(this.level / 5); }              // 1마리 → 5레벨마다 +1
    spawnDelay() { return Math.max(600, CONFIG.SPAWN_INTERVAL - this.level * 70); } // 간격 단축

    // 스폰 주기 재설정 (레벨업 시 간격을 줄여 스폰이 빨라진다)
    refreshSpawnTimer() {
        if (this.spawnTimer) this.spawnTimer.remove(false);
        this.spawnTimer = this.time.addEvent({ delay: this.spawnDelay(), callback: this.spawnEnemy, callbackScope: this, loop: true });
    }

    spawnEnemy() {
        if (this.isGameOver || this.paused) return;
        const room = this.maxEnemies() - this.enemies.countActive();
        const batch = Math.min(this.spawnBatchSize(), room);
        for (let i = 0; i < batch; i++) this.spawnOneEnemy();
    }

    spawnOneEnemy() {
        const angle = Phaser.Math.FloatBetween(0, Math.PI * 2);
        const distance = Phaser.Math.Between(400, 600);
        const x = this.player.x + Math.cos(angle) * distance;
        const y = this.player.y + Math.sin(angle) * distance;
        const t = this.getEnemyType();

        const key = `enemy_${t.color.toString(16)}`;
        if (!this.textures.exists(key)) {
            const g = this.add.graphics(); g.fillStyle(t.color); g.fillCircle(15, 15, t.size);
            g.generateTexture(key, 30, 30); g.destroy();
        }
        const enemy = this.enemies.create(x, y, key);
        enemy.setData('hp', t.hp); enemy.setData('damage', t.damage);
        enemy.setData('speed', t.speed); enemy.setData('xp', t.xp);
        enemy.body.setCircle(t.size, 15 - t.size, 15 - t.size);
    }

    getEnemyType() {
        // 성장 배수: 레벨/시간에 따라 완만하게 상승 (기존 0.1 → 0.05), 속도 상승은 별도로 더 완만하게.
        const m = 1 + (this.level - 1) * 0.05 + (this.gameTime / 60) * 0.05;
        const sm = 1 + (this.level - 1) * 0.02 + (this.gameTime / 60) * 0.03; // 이동속도 성장(둔화)
        const types = [
            { color: 0xe94560, size: 12, hp: Math.floor(CONFIG.ENEMY_BASE_HP * m), damage: CONFIG.ENEMY_DAMAGE, speed: CONFIG.ENEMY_BASE_SPEED * sm, xp: 16 },
            { color: 0xf9ed69, size: 8, hp: Math.floor(CONFIG.ENEMY_BASE_HP * 0.5 * m), damage: CONFIG.ENEMY_DAMAGE * 0.5, speed: CONFIG.ENEMY_BASE_SPEED * 1.4 * sm, xp: 22 },
            { color: 0x6a0572, size: 18, hp: Math.floor(CONFIG.ENEMY_BASE_HP * 1.8 * m), damage: CONFIG.ENEMY_DAMAGE * 1.4, speed: CONFIG.ENEMY_BASE_SPEED * 0.6 * sm, xp: 34 },
        ];
        const w = [60, 25, 15];
        if (this.level >= 3) { w[1] += 10; w[2] += 5; w[0] -= 15; }
        if (this.level >= 5) { w[1] += 10; w[2] += 10; w[0] -= 20; }
        const roll = Phaser.Math.Between(1, 100);
        if (roll <= w[0]) return types[0];
        if (roll <= w[0] + w[1]) return types[1];
        return types[2];
    }

    updateEnemies() {
        this.enemies.children.iterate((e) => {
            if (!e || !e.active) return;
            const a = Phaser.Math.Angle.Between(e.x, e.y, this.player.x, this.player.y);
            const s = e.getData('speed');
            e.setVelocity(Math.cos(a) * s, Math.sin(a) * s);
        });
    }

    // 일정 반경 내 경험치 오브를 플레이어로 끌어당긴다(레벨업 수집 편의).
    updateXPMagnet() {
        const R = 160;
        this.xpOrbs.children.iterate((orb) => {
            if (!orb || !orb.active) return;
            const d = Phaser.Math.Distance.Between(this.player.x, this.player.y, orb.x, orb.y);
            if (d < R) {
                const a = Phaser.Math.Angle.Between(orb.x, orb.y, this.player.x, this.player.y);
                orb.setVelocity(Math.cos(a) * 300, Math.sin(a) * 300);
            }
        });
    }

    // --- 메인 루프 ---
    update(time, delta) {
        if (this.isGameOver || this.paused) return;

        const mv = this.handlePlayerMovement();
        this.updateEnemies();
        this.updateXPMagnet();
        this.fireWeapons(delta, time);
        this.cleanupBullets(time);
        this.updatePlayerAnim(mv.vx, mv.vy);
        this.updateTimeDisplay();
    }

    handlePlayerMovement() {
        let vx = 0, vy = 0;
        if (this.touchMoveActive && this.input.activePointer.isDown) {
            const world = this.cameras.main.getWorldPoint(this.input.activePointer.x, this.input.activePointer.y);
            const dx = world.x - this.player.x, dy = world.y - this.player.y;
            const d = Math.sqrt(dx * dx + dy * dy);
            if (d > 20) { vx = dx / d; vy = dy / d; }
        } else {
            if (this.cursors.left.isDown || this.wasd.left.isDown) vx = -1;
            else if (this.cursors.right.isDown || this.wasd.right.isDown) vx = 1;
            if (this.cursors.up.isDown || this.wasd.up.isDown) vy = -1;
            else if (this.cursors.down.isDown || this.wasd.down.isDown) vy = 1;
            if (vx !== 0 && vy !== 0) { vx *= 0.707; vy *= 0.707; }
        }
        this.player.setVelocity(vx * this.playerSpeed, vy * this.playerSpeed);
        return { vx, vy };
    }

    updatePlayerAnim(vx, vy) {
        if (vx < -0.01) this.facing = -1; else if (vx > 0.01) this.facing = 1;
        this.player.setFlipX(this.facing === -1);
        const moving = vx !== 0 || vy !== 0;
        this.player.play(moving ? 'walk' : 'idle', true);
    }

    // --- 무기 발동 ---
    fireWeapons(delta, time) {
        for (const key of Object.keys(this.equipped)) {
            const st = this.equipped[key];
            st.cd -= delta;
            if (st.cd <= 0) { this.fireWeapon(key, time); st.cd = WEAPONS[key].cooldown; }
        }
    }

    fireWeapon(key, time) {
        const def = WEAPONS[key], st = this.equipped[key];
        const dmg = def.damage + this.bonusAttack;
        if (def.type === 'melee') {
            const range = def.range + (def.rangeStep ? (st.level - 1) * def.rangeStep : 0);
            let hit = false;
            this.enemies.children.iterate((e) => {
                if (!e || !e.active) return;
                if (Phaser.Math.Distance.Between(this.player.x, this.player.y, e.x, e.y) <= range) { this.damageEnemy(e, dmg); hit = true; }
            });
            if (hit || key === 'umbrella') { this.meleeEffect(key, range); }
        } else {
            const target = this.nearestEnemy(700);
            if (!target) return;
            const base = Phaser.Math.Angle.Between(this.player.x, this.player.y, target.x, target.y);
            const n = def.bullets + (st.level - 1) * 3;
            if (n <= 1) {
                this.spawnBullet(base + Phaser.Math.FloatBetween(-def.spread / 2, def.spread / 2), def, dmg, key, time);
            } else {
                const step = def.spread / (n - 1);
                for (let i = 0; i < n; i++) this.spawnBullet(base - def.spread / 2 + i * step, def, dmg, key, time);
            }
        }
    }

    nearestEnemy(maxDist) {
        let best = null, bd = maxDist;
        this.enemies.children.iterate((e) => {
            if (!e || !e.active) return;
            const d = Phaser.Math.Distance.Between(this.player.x, this.player.y, e.x, e.y);
            if (d < bd) { bd = d; best = e; }
        });
        return best;
    }

    spawnBullet(angle, def, dmg, key, time) {
        const b = this.bullets.create(this.player.x, this.player.y, 'bullet_' + key);
        b.setDepth(9);
        b.setData('damage', dmg);
        b.setData('die', time + 1100);
        this.physics.velocityFromRotation(angle, def.speed, b.body.velocity);
    }

    meleeEffect(key, range) {
        const color = key === 'umbrella' ? 0x66aaff : 0xffffff;
        const g = this.add.graphics().setDepth(8);
        g.lineStyle(3, color, 0.7); g.strokeCircle(this.player.x, this.player.y, range);
        g.fillStyle(color, 0.12); g.fillCircle(this.player.x, this.player.y, range);
        this.tweens.add({ targets: g, alpha: 0, duration: 220, onComplete: () => g.destroy() });
    }

    damageEnemy(enemy, dmg) {
        if (!enemy.active) return;
        const hp = enemy.getData('hp') - dmg;
        enemy.setData('hp', hp);
        this.tweens.add({ targets: enemy, tint: 0xffffff, duration: 40, yoyo: true });
        if (hp <= 0) this.killEnemy(enemy);
    }

    onBulletHit(bullet, enemy) {
        if (!bullet.active || !enemy.active) return;
        const dmg = bullet.getData('damage');
        bullet.destroy();
        this.damageEnemy(enemy, dmg);
    }

    cleanupBullets(time) {
        this.bullets.children.iterate((b) => { if (b && b.active && time > b.getData('die')) b.destroy(); });
    }

    killEnemy(enemy) {
        this.createXPOrb(enemy.x, enemy.y, enemy.getData('xp'));
        const p = this.add.graphics(); p.fillStyle(0xe94560);
        for (let i = 0; i < 5; i++) p.fillCircle(enemy.x + Phaser.Math.Between(-10, 10), enemy.y + Phaser.Math.Between(-10, 10), 3);
        this.tweens.add({ targets: p, alpha: 0, duration: 300, onComplete: () => p.destroy() });
        enemy.destroy();
        this.kills++;
        this.killText.setText(`Kills: ${this.kills}`);
    }

    createXPOrb(x, y, value) {
        if (!this.textures.exists('xp_orb')) {
            const g = this.add.graphics();
            g.fillStyle(0x7b68ee); g.fillCircle(8, 8, 6);
            g.fillStyle(0xaaaaff); g.fillCircle(6, 6, 2);
            g.generateTexture('xp_orb', 16, 16); g.destroy();
        }
        const orb = this.xpOrbs.create(x, y, 'xp_orb');
        orb.setData('value', value);
        this.tweens.add({ targets: orb, y: y - 20, duration: 200, yoyo: true, ease: 'Quad.easeOut' });
    }

    collectXP(player, orb) {
        this.xp += orb.getData('value');
        orb.destroy();
        while (this.xp >= this.xpToNext) { this.xp -= this.xpToNext; this.levelUp(); }
        this.updateXPBar();
        this.processChoiceQueue();
    }

    // --- 레벨업 & 선택 ---
    levelUp() {
        this.level++;
        this.xpToNext = Math.floor(CONFIG.XP_TO_LEVEL * (1 + (this.level - 1) * 0.28));
        this.levelText.setText(`Lv. ${this.level}`);
        this.refreshSpawnTimer(); // 레벨업 시 스폰 간격 단축(적 증가)

        const t = this.add.text(this.player.x, this.player.y - 50, 'LEVEL UP!', { fontSize: '24px', fill: '#f9ed69', stroke: '#000', strokeThickness: 4 }).setOrigin(0.5).setDepth(100);
        this.tweens.add({ targets: t, y: this.player.y - 100, alpha: 0, duration: 1000, onComplete: () => t.destroy() });
        this.cameras.main.flash(200, 249, 237, 105);

        if (this.level % 10 === 0) this.pendingChoices.push('weapon');
        else if (this.level % 5 === 0) this.pendingChoices.push('stat');
        else this.applyMinorBonus();
    }

    applyMinorBonus() {
        this.maxHP += 6;
        this.playerHP = Math.min(this.maxHP, this.playerHP + 16);
        this.updateHPBar();
    }

    processChoiceQueue() {
        if (this.paused || this.pendingChoices.length === 0) return;
        const kind = this.pendingChoices.shift();
        if (kind === 'stat') this.showStatChoice();
        else this.showWeaponChoice();
    }

    showStatChoice() {
        this.showChoice('레벨 업! 능력치 강화', [
            { emoji: '❤️', title: '체력 +30', desc: '최대 체력 증가 & 회복', onPick: () => { this.maxHP += 30; this.playerHP += 30; this.updateHPBar(); } },
            { emoji: '👟', title: '이동속도 +25', desc: '더 빠르게 이동', onPick: () => { this.playerSpeed += 25; } },
            { emoji: '⚔️', title: '공격력 +8', desc: '모든 무기 데미지 증가', onPick: () => { this.bonusAttack += 8; } },
        ]);
    }

    showWeaponChoice() {
        const pool = Phaser.Utils.Array.Shuffle(SUB_WEAPONS.slice()).slice(0, 3);
        const cards = pool.map((key) => {
            const def = WEAPONS[key], owned = this.equipped[key];
            if (owned) {
                const lv = owned.level;
                const desc = def.type === 'gun'
                    ? `탄환 ${def.bullets + (lv - 1) * 3} → ${def.bullets + lv * 3}발`
                    : `공격 범위 ${def.range + (lv - 1) * def.rangeStep} → ${def.range + lv * def.rangeStep}`;
                return { icon: key, title: `${def.name} 강화 Lv.${lv}→${lv + 1}`, desc, onPick: () => { owned.level++; } };
            }
            return { icon: key, title: `${def.name} 새 장착`, desc: this.weaponDesc(key), onPick: () => { this.equipped[key] = { level: 1, cd: 0 }; } };
        });
        this.showChoice('레벨 10! 서브무기 장착 / 강화', cards);
    }

    weaponDesc(key) {
        return { pistol: '가장 가까운 적에게 탄환 발사', shotgun: '넓게 퍼지는 산탄', machinegun: '빠른 연사', knife: '주변 근접 범위 공격' }[key] || '';
    }

    showChoice(title, cards) {
        this.paused = true;
        this.physics.pause();
        if (this.spawnTimer) this.spawnTimer.paused = true;

        const overlay = document.getElementById('vs-choice');
        document.getElementById('vs-choice-title').textContent = title;
        const wrap = document.getElementById('vs-choice-cards');
        wrap.innerHTML = '';
        cards.forEach((cd) => {
            const btn = document.createElement('button');
            btn.className = 'vs-choice-card';
            const icon = cd.icon
                ? `<img class="vs-cc-icon" src="/images/mini-game/vampire-survivors/icons/${cd.icon}.png" alt="">`
                : `<div class="vs-cc-emoji">${cd.emoji || ''}</div>`;
            btn.innerHTML = `${icon}<div class="vs-cc-title">${cd.title}</div><div class="vs-cc-desc">${cd.desc}</div>`;
            btn.addEventListener('click', () => {
                overlay.hidden = true;
                cd.onPick();
                this.updateWeaponHUD();
                if (this.pendingChoices.length) this.processChoiceQueue();
                else this.resumePlay();
            });
            wrap.appendChild(btn);
        });
        overlay.hidden = false;
    }

    resumePlay() {
        this.paused = false;
        this.physics.resume();
        if (this.spawnTimer) this.spawnTimer.paused = false;
    }

    // --- 피격 / 시간 / 게임오버 ---
    onPlayerHit(player, enemy) {
        this.playerHP -= enemy.getData('damage') * 0.010;
        this.updateHPBar();
        if (Math.random() < 0.1) this.cameras.main.shake(100, 0.005);
        if (this.playerHP <= 0) this.gameOver();
    }

    updateTimeDisplay() {
        const m = Math.floor(this.gameTime / 60).toString().padStart(2, '0');
        const s = (this.gameTime % 60).toString().padStart(2, '0');
        this.timeText.setText(`${m}:${s}`);
    }

    gameOver() {
        if (this.isGameOver) return;
        this.isGameOver = true;
        this.physics.pause();
        this.player.play('death', true);

        const gw = this.scale.width, gh = this.scale.height;
        const cx = this.cameras.main.scrollX + gw / 2, cy = this.cameras.main.scrollY + gh / 2;
        const overlay = this.add.graphics().setDepth(200);
        overlay.fillStyle(0x000000, 0.6); overlay.fillRect(this.cameras.main.scrollX, this.cameras.main.scrollY, gw, gh);
        this.add.text(cx, cy - 70, 'GAME OVER', { fontSize: '48px', fill: '#e94560', stroke: '#000', strokeThickness: 6 }).setOrigin(0.5).setDepth(201);
        this.add.text(cx, cy + 6,
            `생존 ${Math.floor(this.gameTime / 60)}분 ${this.gameTime % 60}초  ·  Lv.${this.level}  ·  처치 ${this.kills}`,
            { fontSize: '20px', fill: '#fff', stroke: '#000', strokeThickness: 3, align: 'center' }).setOrigin(0.5).setDepth(201);

        // 랭킹 오버레이(닉네임 입력 → 등록 → 랭킹 → 다시하기). 점수 = 생존초×10 + 처치×2
        window.MiniGameRanking?.show(this.gameTime * 10 + this.kills * 2);
    }
}

// ============================================================================
// 캐릭터 선택 → 게임 시작
// ============================================================================
let game = null;

function getPhaserConfig() {
    const size = getGameSize();
    return {
        type: Phaser.AUTO,
        width: size.width,
        height: size.height,
        parent: 'game-container',
        backgroundColor: '#1a1a2e',
        scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
        physics: { default: 'arcade', arcade: { debug: false } },
        scene: [GameScene],
    };
}

document.querySelectorAll('.vs-char-card[data-char]').forEach((card) => {
    card.addEventListener('click', function (e) {
        e.preventDefault();
        const scrollY = window.scrollY || window.pageYOffset;
        window.__vsChar = this.getAttribute('data-char');

        document.getElementById('startScreen').style.display = 'none';
        const gc = document.getElementById('game-container');
        gc.style.display = 'flex';
        gc.addEventListener('contextmenu', (ev) => ev.preventDefault(), false);
        window.scrollTo(0, scrollY);

        if (!game) game = new Phaser.Game(getPhaserConfig());
        setTimeout(() => window.scrollTo(0, scrollY), 10);
    });
});
    </script>
    @endpush

    @push('styles')
    <style>
        /* 캐릭터 선택 */
        .vs-char-grid { display: flex; gap: 16px; flex-wrap: wrap; justify-content: center; margin-top: 18px; }
        .vs-char-card {
            width: 130px; background: #141426; border: 2px solid #2a2a44; border-radius: 14px;
            padding: 14px 10px; cursor: pointer; color: #e2e8f0; transition: transform .12s, border-color .12s;
            display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .vs-char-card[data-char]:hover { transform: translateY(-4px); border-color: #6366f1; }
        .vs-char-card.locked { opacity: .5; cursor: default; }
        .vs-char-portrait {
            width: 96px; height: 96px; border-radius: 10px; background-color: #0d0d1a;
            background-repeat: no-repeat; image-rendering: pixelated;
            display: flex; align-items: center; justify-content: center;
        }
        .vs-char-portrait.vs-locked { font-size: 40px; color: #444; }
        .vs-char-name { font-weight: 800; font-size: 15px; }
        .vs-char-weapon { font-size: 12px; color: #94a3b8; }

        /* 레벨업 선택 오버레이 */
        .vs-choice {
            position: fixed; inset: 0; z-index: 9998;
            display: flex; align-items: center; justify-content: center;
            background: rgba(2, 6, 23, 0.82); backdrop-filter: blur(4px); padding: 20px;
            font-family: 'Outfit', 'Noto Sans KR', sans-serif;
        }
        .vs-choice[hidden] { display: none; }
        .vs-choice-box { width: 100%; max-width: 560px; text-align: center; }
        .vs-choice-box h3 { color: #f9ed69; font-size: 22px; font-weight: 800; margin: 0 0 18px; }
        .vs-choice-cards { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }
        .vs-choice-card {
            flex: 1 1 150px; max-width: 170px; background: #0f172a; border: 2px solid #1e293b;
            border-radius: 14px; padding: 18px 12px; cursor: pointer; color: #e2e8f0;
            display: flex; flex-direction: column; align-items: center; gap: 8px; transition: transform .12s, border-color .12s;
        }
        .vs-choice-card:hover { transform: translateY(-4px); border-color: #6366f1; }
        .vs-cc-icon { width: 56px; height: 66px; object-fit: contain; image-rendering: pixelated; }
        .vs-cc-emoji { font-size: 44px; line-height: 66px; }
        .vs-cc-title { font-weight: 800; font-size: 15px; }
        .vs-cc-desc { font-size: 12px; color: #94a3b8; }
    </style>
    @endpush

    <div class="game-instructions">
        <h3>게임 방법</h3>
        <ul>
            <li>시작 화면에서 <strong>캐릭터를 선택</strong>하세요. 캐릭터마다 고유 메인 무기가 있습니다. (레이니 = 우산)</li>
            <li class="desktop-only">WASD 또는 방향키로 이동</li>
            <li class="mobile-only">화면을 터치한 방향으로 이동</li>
            <li>장착한 무기는 <strong>자동으로 발동</strong>합니다. 오래 살아남아 경험치를 모으세요.</li>
            <li><strong>레벨 5</strong>마다 체력 · 이동속도 · 공격력 중 하나를 선택합니다.</li>
            <li><strong>레벨 10</strong>마다 서브무기(총 · 샷건 · 기관총 · 칼)를 새로 장착하거나 강화합니다.</li>
        </ul>
    </div>
@endsection
