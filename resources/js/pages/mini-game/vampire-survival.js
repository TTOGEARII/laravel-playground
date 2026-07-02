// ============================================================================
// 뱀파이어 서바이벌 — 캐릭터 선택 + 스프라이트 애니메이션 + 다중 무기 누적
// (전역 오염/콘솔 값 조작 방지를 위해 전체를 IIFE 로 캡슐화 — CONFIG/game/GameScene 등 비공개)
// ============================================================================
(function () {
'use strict';
let selectedChar = 'rainy'; // 선택한 캐릭터(전역 노출 안 함)
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
    PLAYER_HP: 90,
    ENEMY_BASE_SPEED: 95,
    ENEMY_BASE_HP: 22,
    ENEMY_DAMAGE: 11,         // 접촉 피해 상향(스웜에 닿으면 위험하게)
    HP_GROWTH_PER_MIN: 1.16,  // 적 체력: 분당 지수 성장(1.1~1.3). 밸런스의 심장.
    XP_TO_LEVEL: 70,          // 레벨1→2 기준량(이후 선형 증가). 물량이 늘어도 레벨업이 쉬워지지 않게 높게 유지.
    SPAWN_INTERVAL: 780,      // 고정 스폰 주기. 밀도는 시간에 따라 batch/최대수로 상승(스웜 형성)
    LOG_INTERVAL: 5000,       // 밸런스 로그 주기(ms)
    // 특수기 전역 딜 = 현재 일반몹 HP 곡선 × 이 배율. 탱크(×1.8)까지 확실히 정리하되,
    // HP를 훨씬 높게 설계한 보스는 한 방에 죽지 않도록 유한값으로 둔다.
    ULT_DAMAGE_MUL: 3,
};

// 캐릭터 스프라이트 그리드 (Charactor_move/idle_sprite.png: 4열×4행, 셀 256×256, 투명 배경)
const SHEET = { cell: 256, cols: 4, rows: 4 };

// 캐릭터별 고유 메인 무기
const CHARACTERS = {
    rainy: { name: '레이니', main: 'umbrella' },
};

// 무기 정의 (main=항상 발동 메인 / sub=레벨10에 장착·강화)
const WEAPONS = {
    // 근접(우산·칼)은 한 방이 세고, 원거리(샷건·기관총)는 근접보다 딜이 약한 대신 안전하게 사거리.
    // 우산(항상 켜진 메인)은 방치 클리어 방지를 위해 사거리/데미지를 낮춤.
    umbrella:   { name: '우산', kind: 'main', type: 'melee', cooldown: 560, damage: 15, range: 92 },
    // 칼: 적 방향으로 베는 초승달(부채꼴) 아크. 레벨업 시 슬래시 방향이 앞·뒤·좌·우 순으로 1개씩 늘어난다.
    knife:      { name: '칼',   kind: 'sub',  type: 'melee', arc: true, cooldown: 430, damage: 17, range: 110, arcHalfAngle: Math.PI / 5 },
    shotgun:    { name: '샷건', kind: 'sub',  type: 'gun',   cooldown: 1050, damage: 5, bullets: 5, spread: 0.6, speed: 540 },
    machinegun: { name: '기관총', kind: 'sub', type: 'gun',  cooldown: 320, damage: 2, bullets: 1, spread: 0.22, speed: 720 },
};
const SUB_WEAPONS = ['shotgun', 'machinegun', 'knife'];
const BULLET_COLOR = { shotgun: 0xffb74d, machinegun: 0x4dd0e1 };

class GameScene extends Phaser.Scene {
    constructor() { super({ key: 'GameScene' }); }

    init() {
        this.charKey = selectedChar;
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
        this.special = 0;       // 특수기 게이지(처치 시 충전)
        this.specialMax = 50;   // 50마리 처치 시 발동 가능
        this._ultActive = false;

        // 밸런스 로그: 유효 딜/유입 체력 누적(주기적으로 DPS·적HP유입으로 환산)
        this._dmgAccum = 0;
        this._hpInfluxAccum = 0;
        window.__vsBalanceLog = [];

        // 치팅 방지 sanity check 용: 스폰 총합·시작 실시간(점수/킬 상한 검증에 사용)
        this._spawnedTotal = 0;
        this._realStart = 0;

        // 장착 무기: 캐릭터 메인 무기부터 시작
        const main = CHARACTERS[this.charKey].main;
        this.equipped = {};
        this.equipped[main] = { level: 1, cd: 0 };
    }

    preload() {
        const base = '/images/mini-game/vampire-survivors';
        // 캐릭터(레이니): 걷기/대기 시트 (4x4/256)
        this.load.image('char2', `${base}/charactor/rainy/Charactor_move_sprite.png`);
        this.load.image('charIdle', `${base}/charactor/rainy/Charactor_idle_sprite.png`);
        // 스테이지1: 배경 + 적 스프라이트(각 2x2/512, 4프레임)
        this.load.image('bg', `${base}/stage1/background.png`);
        this.load.spritesheet('enemy_normal', `${base}/stage1/enemy_nomal.png`, { frameWidth: 512, frameHeight: 512 });
        this.load.spritesheet('enemy_fast', `${base}/stage1/enemy_fast.png`, { frameWidth: 512, frameHeight: 512 });
        this.load.spritesheet('enemy_tank', `${base}/stage1/enemy_tank.png`, { frameWidth: 512, frameHeight: 512 });
        // 궁극기(우산 폭풍): 시전 시퀀스(캐릭터+폭풍) / 폭발 VFX — 각 3x2 6프레임 시트
        const skill = `${base}/charactor/rainy/skill`;
        this.load.spritesheet('castSheet', `${skill}/storm1.png`, { frameWidth: 341, frameHeight: 343 });
        this.load.spritesheet('boomSheet', `${skill}/storm2.png`, { frameWidth: 341, frameHeight: 343 });
    }

    create() {
        this._realStart = performance.now();
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
        this.setupSpecial();

        this.refreshSpawnTimer();
        this.time.addEvent({ delay: 1000, callback: () => { if (!this.isGameOver && !this.paused) this.gameTime++; }, loop: true });
        this.time.addEvent({ delay: CONFIG.LOG_INTERVAL, loop: true, callback: () => this.logBalance() });

        this.cameras.main.startFollow(this.player, true, 0.1, 0.1);
    }

    // --- 스프라이트 프레임/애니메이션 ---
    registerFrames() {
        // 두 시트(char2=걷기 c2_, charIdle=대기 ci_)를 같은 4x4/256 그리드로 등록
        const sheets = { char2: 'c2', charIdle: 'ci' };
        for (const [texKey, pfx] of Object.entries(sheets)) {
            const tex = this.textures.get(texKey);
            for (let r = 0; r < SHEET.rows; r++) {
                for (let c = 0; c < SHEET.cols; c++) {
                    tex.add(`${pfx}_${r}_${c}`, 0, c * SHEET.cell, r * SHEET.cell, SHEET.cell, SHEET.cell);
                }
            }
        }
    }

    makeAnims() {
        const FW = (r, c) => ({ key: 'char2', frame: `c2_${r}_${c}` });    // 걷기
        const FI = (r, c) => ({ key: 'charIdle', frame: `ci_${r}_${c}` }); // 대기
        const def = (key, frames, fps, repeat = -1) => {
            if (!this.anims.exists(key)) this.anims.create({ key, frames, frameRate: fps, repeat });
        };
        // 걷기·대기 모두 시트의 16프레임 전부(행 우선)로 부드럽게. death 는 정지 프레임.
        const walk = [], idle = [];
        for (let r = 0; r < SHEET.rows; r++) {
            for (let c = 0; c < SHEET.cols; c++) { walk.push(FW(r, c)); idle.push(FI(r, c)); }
        }
        def('idle', idle, 8);
        def('walk', walk, 14);
        def('death', [FW(0, 0)], 1, 0);

        // 적 애니메이션 (2x2 시트의 4프레임 순환)
        for (const key of ['enemy_normal', 'enemy_fast', 'enemy_tank']) {
            if (!this.anims.exists(key)) {
                this.anims.create({ key, frames: this.anims.generateFrameNumbers(key, { start: 0, end: 3 }), frameRate: 5, repeat: -1 });
            }
        }

        // 궁극기 VFX — 절대 루프 금지(repeat: 0), 각 한 번만 재생
        if (!this.anims.exists('vfx_cast')) {
            this.anims.create({ key: 'vfx_cast', frames: this.anims.generateFrameNumbers('castSheet', { start: 0, end: 5 }), frameRate: 8, repeat: 0 });
        }
        if (!this.anims.exists('vfx_boom')) {
            this.anims.create({ key: 'vfx_boom', frames: this.anims.generateFrameNumbers('boomSheet', { start: 0, end: 5 }), frameRate: 9, repeat: 0 });
        }
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
        // 맵을 배경 이미지의 3배 크기로 넓히고, 배경은 TileSprite로 반복해서 깐다.
        const src = this.textures.get('bg').getSourceImage();
        const MAP_SCALE = 3; // 맵 배율 (배경 한 장 대비)
        this.worldW = src.width * MAP_SCALE;
        this.worldH = src.height * MAP_SCALE;
        this.physics.world.setBounds(0, 0, this.worldW, this.worldH);
        // 원본 해상도 그대로 반복 → 선명함 유지
        this.add.tileSprite(0, 0, this.worldW, this.worldH, 'bg').setOrigin(0, 0).setDepth(-10);
        // 카메라가 맵 밖으로 넘어가지 않게 경계 고정
        this.cameras.main.setBounds(0, 0, this.worldW, this.worldH);

        // 반복 이음새/격자감을 감추기 위한 장치 두 가지
        this.scatterGroundDecals(); // ① 월드 전역에 지면 장식물 랜덤 배치
        this.createVignette();      // ② 화면 가장자리 비네트(먼 반복부로 시선 안 가게)
    }

    // 반복 패턴을 깨기 위해 바위/마른 풀/흙자국 데칼을 절차적으로 만들어 월드에 흩뿌린다.
    scatterGroundDecals() {
        const mk = (key, w, h, draw) => {
            if (this.textures.exists(key)) return;
            const g = this.add.graphics();
            draw(g);
            g.generateTexture(key, w, h);
            g.destroy();
        };
        mk('decal_rock', 44, 30, (g) => {
            g.fillStyle(0x4a4038, 1); g.fillEllipse(22, 17, 38, 22);
            g.fillStyle(0x5b5148, 1); g.fillEllipse(18, 13, 22, 13);
        });
        mk('decal_grass', 42, 30, (g) => {
            g.fillStyle(0x5c6b3a, 1);
            for (let i = 0; i < 6; i++) { const x = 6 + i * 5; g.fillTriangle(x, 28, x - 3, 8 + (i % 2) * 5, x + 3, 28); }
        });
        mk('decal_patch', 56, 40, (g) => {
            g.fillStyle(0x312820, 0.6); g.fillEllipse(28, 20, 52, 36);
        });

        const decals = ['decal_rock', 'decal_grass', 'decal_patch'];
        const count = Math.round((this.worldW * this.worldH) / 95000); // 면적 비례 밀도
        for (let i = 0; i < count; i++) {
            const key = Phaser.Utils.Array.GetRandom(decals);
            const x = Phaser.Math.Between(30, this.worldW - 30);
            const y = Phaser.Math.Between(30, this.worldH - 30);
            this.add.image(x, y, key)
                .setDepth(-9)
                .setScale(Phaser.Math.FloatBetween(0.7, 1.9))
                .setAngle(Phaser.Math.Between(0, 359))
                .setAlpha(Phaser.Math.FloatBetween(0.5, 0.9));
        }
    }

    // 화면 고정 비네트(가장자리 어둡게) — 먼 곳의 반복 패턴을 눈에 덜 띄게 한다.
    createVignette() {
        if (!this.textures.exists('vignette')) {
            const size = 512;
            const cv = this.textures.createCanvas('vignette', size, size);
            const ctx = cv.getContext();
            const grd = ctx.createRadialGradient(size / 2, size / 2, size * 0.28, size / 2, size / 2, size * 0.5);
            grd.addColorStop(0, 'rgba(0,0,0,0)');
            grd.addColorStop(0.7, 'rgba(0,0,0,0)');
            grd.addColorStop(1, 'rgba(0,0,0,0.5)');
            ctx.fillStyle = grd; ctx.fillRect(0, 0, size, size);
            cv.refresh();
        }
        const cam = this.cameras.main;
        this.add.image(cam.width / 2, cam.height / 2, 'vignette')
            .setScrollFactor(0)
            .setDepth(45) // 게임 오브젝트 위, UI(100) 아래
            .setDisplaySize(cam.width * 1.05, cam.height * 1.05);
    }

    createPlayer() {
        // 맵 중앙에서 시작
        this.player = this.physics.add.sprite(this.worldW / 2, this.worldH / 2, 'charIdle', 'ci_0_0');
        this.player.setScale(0.29);
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
    // 밀도(스폰)는 시간에 따라 "완만히" 상승 — 체력(지수)과 밀도를 동시에 급격히 올리지 않는다.
    // 밀도(스폰) = 시간 + 레벨 단계. 레벨 10단위(10·20·30…)마다 물량을 크게 늘려 난이도 급상승.
    tier() { return Math.floor(this.level / 10); } // 10레벨마다 1단계
    maxEnemies() {
        const cap = 40 + Math.floor((this.gameTime / 60) * 5) + this.tier() * 45; // 단계마다 최대치 +45
        return Math.min(cap, 320);
    }
    spawnBatchSize() {
        const b = 1 + Math.floor((this.gameTime / 60) / 3) + this.tier() * 2; // 단계마다 배치 +2
        return Math.min(b, 16);
    }
    spawnDelay() { return Math.max(CONFIG.SPAWN_INTERVAL - this.tier() * 130, 300); } // 단계마다 리젠 주기 단축(하한 300ms)
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

        const enemy = this.enemies.create(x, y, t.key);
        enemy.play(t.anim);
        enemy.setScale(t.scale);
        enemy.setDepth(5);
        enemy.setData('hp', t.hp); enemy.setData('damage', t.damage);
        enemy.setData('speed', t.speed); enemy.setData('xp', t.xp);
        // 512 프레임 기준 충돌 원(중앙). 표시 반경 ≈ 150 × scale.
        enemy.body.setCircle(150, 256 - 150, 256 - 150);
        this._hpInfluxAccum += t.hp; // 밸런스 로그: 유입 적 체력 누적
        this._spawnedTotal++;        // sanity: 처치 수는 스폰 수를 넘을 수 없음
    }

    getEnemyType() {
        // 체력: 시간(분)에 대한 지수 성장(밸런스 심장). 속도/데미지는 별도로 완만한 선형(상한).
        const minutes = this.gameTime / 60;
        const hpMul = Math.pow(CONFIG.HP_GROWTH_PER_MIN, minutes);
        const spdMul = Math.min(1 + minutes * 0.05, 2.0);
        // 접촉 데미지: 시간 완만 상승 + 레벨10부터 ×1.4 (밀집 스웜이 실제로 위협이 되도록)
        const dmgMul = Math.min(1 + minutes * 0.04, 1.7) * (this.level >= 10 ? 1.4 : 1);
        const types = [
            { key: 'enemy_normal', anim: 'enemy_normal', scale: 0.13, hp: Math.floor(CONFIG.ENEMY_BASE_HP * hpMul), damage: CONFIG.ENEMY_DAMAGE * dmgMul, speed: CONFIG.ENEMY_BASE_SPEED * spdMul, xp: 8 },
            { key: 'enemy_fast', anim: 'enemy_fast', scale: 0.10, hp: Math.floor(CONFIG.ENEMY_BASE_HP * 0.5 * hpMul), damage: CONFIG.ENEMY_DAMAGE * 0.5 * dmgMul, speed: CONFIG.ENEMY_BASE_SPEED * 1.4 * spdMul, xp: 10 },
            { key: 'enemy_tank', anim: 'enemy_tank', scale: 0.18, hp: Math.floor(CONFIG.ENEMY_BASE_HP * 1.8 * hpMul), damage: CONFIG.ENEMY_DAMAGE * 1.4 * dmgMul, speed: CONFIG.ENEMY_BASE_SPEED * 0.6 * spdMul, xp: 15 },
        ];
        // 강한 적 등장 확률: 시간에 따라 증가
        const w = [60, 25, 15];
        if (minutes >= 2) { w[1] += 10; w[2] += 5; w[0] -= 15; }
        if (minutes >= 5) { w[1] += 10; w[2] += 10; w[0] -= 20; }
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

        this.sanityCheck(); // 물리적으로 불가능한 값(조작 의심) 보정
        const mv = this.handlePlayerMovement();
        this.updateEnemies();
        this.updateXPMagnet();
        this.fireWeapons(delta, time);
        this.cleanupBullets(time);
        this.updatePlayerAnim(mv.vx, mv.vy);
        this.updateTimeDisplay();
    }

    // 값 검증(치팅 방지): 물리적으로 불가능한 값이 나오면 유효 범위로 리셋한다.
    sanityCheck() {
        // HP: 0~최대치를 벗어나면 클램프 (조작으로 HP를 부풀리는 것 무력화)
        if (!Number.isFinite(this.playerHP) || this.playerHP > this.maxHP) this.playerHP = this.maxHP;
        if (this.playerHP < 0) this.playerHP = 0;
        // 처치 수: 지금까지 스폰된 적 수를 넘을 수 없음
        if (!Number.isFinite(this.kills) || this.kills < 0) this.kills = 0;
        if (this.kills > this._spawnedTotal) this.kills = this._spawnedTotal;
        // 생존 시간: 실제 경과 시간(+여유)을 넘을 수 없음
        const realElapsed = Math.floor((performance.now() - this._realStart) / 1000);
        if (this.gameTime > realElapsed + 3) this.gameTime = realElapsed;
        // 특수기 게이지: 상한 초과 방지
        if (this.special > this.specialMax) this.special = this.specialMax;
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
            if (st.cd <= 0) { this.fireWeapon(key, time); st.cd = this.effectiveCooldown(key); }
        }
    }

    // 총 강화는 탄환 수가 아니라 연사속도(쿨다운 감소)로 반영한다.
    // 레벨당 쿨다운 -12%, 기본값의 45%까지 단축(연사 상한).
    effectiveCooldown(key) {
        const def = WEAPONS[key];
        const lv = this.equipped[key]?.level ?? 1;
        return def.type === 'gun' ? Math.round(this.gunCooldownAt(def, lv)) : def.cooldown;
    }

    gunCooldownAt(def, level) {
        return def.cooldown * Math.max(0.45, 1 - (level - 1) * 0.12);
    }

    fireWeapon(key, time) {
        const def = WEAPONS[key], st = this.equipped[key];
        const dmg = def.damage + this.bonusAttack;
        if (def.type === 'melee') {
            if (def.arc) { this.fireKnife(def, st, dmg); return; }
            // 전체 원형: 사거리 내 모든 적 타격(원복).
            const range = def.range;
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
            const n = def.bullets; // 탄환 수는 고정 — 강화는 연사속도로 반영
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

    // 칼: 적 방향으로 초승달 아크 슬래시. 아크(사거리+각도) 안에 들어온 적은 모두 피격.
    fireKnife(def, st, dmg) {
        const target = this.nearestEnemy(760);
        if (!target) return; // 벨 대상이 없으면 발동 안 함
        const front = Phaser.Math.Angle.Between(this.player.x, this.player.y, target.x, target.y);
        const angles = this.knifeSlashAngles(st.level, front);
        const range = def.range, half = def.arcHalfAngle;

        this.enemies.children.iterate((e) => {
            if (!e || !e.active) return;
            if (Phaser.Math.Distance.Between(this.player.x, this.player.y, e.x, e.y) > range) return;
            const ea = Phaser.Math.Angle.Between(this.player.x, this.player.y, e.x, e.y);
            for (const sa of angles) {
                if (Math.abs(Phaser.Math.Angle.Wrap(ea - sa)) <= half) { this.damageEnemy(e, dmg); break; }
            }
        });

        this.knifeEffect(angles, range, half);
    }

    // 레벨별 슬래시 방향: 앞(0)·뒤(π)·좌(-π/2)·우(π/2) 순으로 1개씩. 4방향을 다 채우면
    // 다음 회차부터 같은 방향에 추가 슬래시를 좌우로 벌려(부채꼴) 앞2·뒤2·…앞3 처럼 늘린다.
    knifeSlashAngles(level, front) {
        const base = [0, Math.PI, -Math.PI / 2, Math.PI / 2]; // 앞·뒤·좌·우
        const out = [];
        for (let i = 0; i < level; i++) {
            const pass = Math.floor(i / 4);
            const spread = pass === 0 ? 0 : (pass % 2 ? 1 : -1) * Math.ceil(pass / 2) * 0.5;
            out.push(front + base[i % 4] + spread);
        }
        return out;
    }

    knifeEffect(angles, range, half) {
        const g = this.add.graphics().setDepth(8);
        g.fillStyle(0xffffff, 0.18);
        g.lineStyle(3, 0xffffff, 0.85);
        for (const a of angles) {
            g.beginPath();
            g.moveTo(this.player.x, this.player.y);
            g.arc(this.player.x, this.player.y, range, a - half, a + half, false);
            g.closePath();
            g.fillPath();
            g.strokePath();
        }
        this.tweens.add({ targets: g, alpha: 0, duration: 200, onComplete: () => g.destroy() });
    }

    damageEnemy(enemy, dmg) {
        if (!enemy.active) return;
        const cur = enemy.getData('hp');
        this._dmgAccum += Math.max(0, Math.min(dmg, cur)); // 밸런스 로그: 오버킬 제외한 유효 딜
        const hp = cur - dmg;
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
        // 특수기 게이지 충전 (특수기 발동으로 죽인 적은 충전에서 제외)
        if (!this._ultActive) {
            this.special = Math.min(this.specialMax, this.special + 1);
            this.updateSpecialBar();
        }
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
        if (this.level % 10 === 0) this.refreshSpawnTimer(); // 10단위 도달: 리젠 주기 단축(난이도 단계 상승)
        // 선형 곡선: 레벨이 오를수록 필요 경험치가 가파르게 증가 → 물량이 늘어도 레벨업이 점점 어려워짐
        this.xpToNext = Math.floor(CONFIG.XP_TO_LEVEL * (1 + (this.level - 1) * 0.60));
        this.levelText.setText(`Lv. ${this.level}`);

        const t = this.add.text(this.player.x, this.player.y - 50, 'LEVEL UP!', { fontSize: '24px', fill: '#f9ed69', stroke: '#000', strokeThickness: 4 }).setOrigin(0.5).setDepth(100);
        this.tweens.add({ targets: t, y: this.player.y - 100, alpha: 0, duration: 1000, onComplete: () => t.destroy() });
        this.cameras.main.flash(200, 249, 237, 105);

        // 보상 주기: 무기는 5레벨마다(빌드 완성 촉진), 능력치는 3레벨마다, 그 외 소폭 자동
        if (this.level % 5 === 0) this.pendingChoices.push('weapon');
        else if (this.level % 3 === 0) this.pendingChoices.push('stat');
        else this.applyMinorBonus();
    }

    applyMinorBonus() {
        this.maxHP += 3;
        this.playerHP = Math.min(this.maxHP, this.playerHP + 12);
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
            { emoji: '❤️', title: '체력 +22', desc: '최대 체력 증가 & 회복', onPick: () => { this.maxHP += 22; this.playerHP += 22; this.updateHPBar(); } },
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
                    ? `연사속도 ↑ (${Math.round(this.gunCooldownAt(def, lv))}ms → ${Math.round(this.gunCooldownAt(def, lv + 1))}ms)`
                    : def.arc
                        ? `슬래시 ${lv} → ${lv + 1}방향 (앞·뒤·좌·우 순 확장)`
                        : `공격 범위 ${def.range + (lv - 1) * (def.rangeStep ?? 0)} → ${def.range + lv * (def.rangeStep ?? 0)}`;
                return { icon: key, title: `${def.name} 강화 Lv.${lv}→${lv + 1}`, desc, onPick: () => { owned.level++; } };
            }
            return { icon: key, title: `${def.name} 새 장착`, desc: this.weaponDesc(key), onPick: () => { this.equipped[key] = { level: 1, cd: 0 }; } };
        });
        this.showChoice('레벨 10! 서브무기 장착 / 강화', cards);
    }

    weaponDesc(key) {
        return { shotgun: '넓게 퍼지는 산탄(원거리)', machinegun: '연사(원거리, 딜 약함)', knife: '적 방향으로 베는 근접 슬래시(레벨업 시 방향 추가)' }[key] || '';
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
        this.playerHP -= enemy.getData('damage') * 0.022;
        this.updateHPBar();
        if (Math.random() < 0.1) this.cameras.main.shake(100, 0.005);
        if (this.playerHP <= 0) this.gameOver();
    }

    updateTimeDisplay() {
        const m = Math.floor(this.gameTime / 60).toString().padStart(2, '0');
        const s = (this.gameTime % 60).toString().padStart(2, '0');
        this.timeText.setText(`${m}:${s}`);
    }

    // --- 밸런스 로그(추후 튜닝용) ---
    // 주기마다 "플레이어 DPS(유효 딜/초)"와 "적 체력 유입/초"를 같은 타임라인에 기록.
    // window.__vsBalanceLog 에 스냅샷이 쌓이고 콘솔에도 출력된다. (JSON.stringify(window.__vsBalanceLog) 로 추출)
    logBalance() {
        if (this.isGameOver || this.paused) return; // 정지(레벨업 선택) 중엔 부분 윈도우 기록 방지
        const sec = CONFIG.LOG_INTERVAL / 1000;
        const dps = Math.round(this._dmgAccum / sec);
        const hpInflux = Math.round(this._hpInfluxAccum / sec);
        const snap = {
            t: this.gameTime,
            dps,                       // 플레이어 초당 유효 딜(파괴한 적 체력/초)
            enemyHpInflux: hpInflux,   // 초당 유입 적 체력(스폰된 적 체력/초)
            ratio: hpInflux ? Math.round(dps / hpInflux * 100) / 100 : null, // DPS/유입 (>1이면 우세)
            alive: true,
            level: this.level,
            hp: Math.round(this.playerHP),
            maxHp: this.maxHP,
            kills: this.kills,
            enemies: this.enemies.countActive(),
            weapons: Object.keys(this.equipped).map((k) => `${WEAPONS[k].name}${this.equipped[k].level}`).join(','),
        };
        window.__vsBalanceLog.push(snap);
        console.log(`[VS ${this._fmtTime(snap.t)}] DPS=${dps} 적HP유입/s=${hpInflux} (딜/유입=${snap.ratio}) Lv${snap.level} HP=${snap.hp}/${snap.maxHp} kills=${snap.kills} 적=${snap.enemies} [${snap.weapons}]`);
        this._dmgAccum = 0;
        this._hpInfluxAccum = 0;
    }

    _fmtTime(s) {
        return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
    }

    // --- 특수기(전역공격) ---
    setupSpecial() {
        this.specialBtn = document.getElementById('vs-special-btn');
        this.specialLabel = document.getElementById('vs-special-label');
        if (this.specialBtn) {
            this.specialBtn.hidden = false;
            this.specialBtn.onclick = (e) => { e.preventDefault(); this.tryActivateSpecial(); };
        }
        // Space 로도 발동. 닉네임 입력 중(게임오버 랭킹)에는 무시하고, 그 외엔 페이지 스크롤 방지.
        this.input.keyboard.on('keydown-SPACE', (event) => {
            if (document.activeElement && document.activeElement.tagName === 'INPUT') return;
            event.preventDefault();
            this.tryActivateSpecial();
        });
        this.updateSpecialBar();
    }

    updateSpecialBar() {
        if (!this.specialBtn) return;
        const pct = Math.floor(this.special / this.specialMax * 100);
        const ready = this.special >= this.specialMax;
        this.specialLabel.textContent = ready ? '발동!' : `${pct}%`;
        this.specialBtn.style.setProperty('--fill', pct + '%');
        this.specialBtn.classList.toggle('ready', ready);
    }

    tryActivateSpecial() {
        if (this.isGameOver || this.paused) return;
        if (this.special < this.specialMax) return;
        this.special = 0;
        this.updateSpecialBar();
        this.castUltimate(this.player.x, this.player.y);
    }

    // 우산 광범위 궁극기: 시전 시퀀스(캐릭터+폭풍) + 폭발 VFX 를 동시에 재생.
    // 두 이펙트는 각자 독립적으로 끝나고 정리되며(길이 달라도 OK), 절대 루프하지 않는다.
    castUltimate(x, y) {
        this.cameras.main.flash(200, 130, 170, 255);

        // 1) 데미지 판정 — 발동 즉시(맵 전체 몹)
        this.dealDamageToAll();

        // 2) 시전 스프라이트: 평소 캐릭터를 숨기고 같은 위치에 시전 시퀀스 재생
        this.player.setVisible(false);
        const cast = this.add.sprite(x, y - 24, 'castSheet', 0).setDepth(11);
        cast.setDisplaySize(200, 201); // 캐릭터 크기에 맞춰(폭풍 포함)
        cast.play('vfx_cast');
        cast.once('animationcomplete', () => {
            cast.destroy();
            if (this.player && this.player.active) this.player.setVisible(true); // 평소 캐릭터 복귀
        });

        // 3) 폭발 VFX: 캐릭터 위에 겹쳐서 동시 재생 — ADD 발광 + 크게 + 더 높은 depth
        const boom = this.add.sprite(x, y, 'boomSheet', 0).setDepth(60);
        boom.setBlendMode(Phaser.BlendModes.ADD);
        boom.setDisplaySize(460, 460);
        boom.play('vfx_boom');
        boom.once('animationcomplete', () => boom.destroy()); // 폭발이 먼저 끝나도 독립적으로 정리
    }

    // 특수기 전역 공격: 반경 제한 없이 맵의 모든 활성 몹에게 딜.
    // 딜은 현재 일반몹 HP 곡선 기준 유한값 — 일반/탱크는 확실히 처치하되 보스(고HP)는 즉사하지 않는다.
    dealDamageToAll() {
        const hpMul = Math.pow(CONFIG.HP_GROWTH_PER_MIN, this.gameTime / 60);
        const dmg = Math.ceil(CONFIG.ENEMY_BASE_HP * hpMul * CONFIG.ULT_DAMAGE_MUL);
        this._ultActive = true; // 궁극기 킬은 특수기 게이지 재충전에서 제외
        this.enemies.children.iterate((e) => {
            if (e && e.active) this.damageEnemy(e, dmg);
        });
        this._ultActive = false;
    }

    gameOver() {
        if (this.isGameOver) return;
        this.isGameOver = true;
        this.sanityCheck(); // 마지막으로 값 검증(점수 산정 전)
        this.physics.pause();
        this.player.play('death', true);

        // 밸런스 로그: 사망(생존 종료) 스냅샷
        window.__vsBalanceLog?.push({ t: this.gameTime, alive: false, gameOver: true, level: this.level, kills: this.kills });
        console.log(`[VS GAMEOVER] 생존 ${this._fmtTime(this.gameTime)} · Lv${this.level} · kills=${this.kills}`);

        const gw = this.scale.width, gh = this.scale.height;
        const cx = this.cameras.main.scrollX + gw / 2, cy = this.cameras.main.scrollY + gh / 2;
        const overlay = this.add.graphics().setDepth(200);
        overlay.fillStyle(0x000000, 0.6); overlay.fillRect(this.cameras.main.scrollX, this.cameras.main.scrollY, gw, gh);
        this.add.text(cx, cy - 70, 'GAME OVER', { fontSize: '48px', fill: '#e94560', stroke: '#000', strokeThickness: 6 }).setOrigin(0.5).setDepth(201);
        this.add.text(cx, cy + 6,
            `생존 ${Math.floor(this.gameTime / 60)}분 ${this.gameTime % 60}초  ·  Lv.${this.level}  ·  처치 ${this.kills}`,
            { fontSize: '20px', fill: '#fff', stroke: '#000', strokeThickness: 3, align: 'center' }).setOrigin(0.5).setDepth(201);

        // 랭킹 오버레이(닉네임 입력 → 등록 → 랭킹 → 다시하기). 점수 = 생존초×10 + 처치×2
        // sanityCheck 로 검증된 값만 사용해 점수를 산정(조작된 값 반영 방지).
        const score = Math.max(0, Math.floor(this.gameTime) * 10 + Math.floor(this.kills) * 2);
        window.MiniGameRanking?.show(score);
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

// --- 시작 메뉴(시작/옵션/조작법) 전환 ---
const vsMenuMain = document.getElementById('vs-menu-main');
const vsPanels = { select: 'vs-menu-select', options: 'vs-menu-options', controls: 'vs-menu-controls' };
document.querySelectorAll('.vs-menu-btn[data-menu]').forEach((btn) => {
    btn.addEventListener('click', () => {
        vsMenuMain.hidden = true;
        document.getElementById(vsPanels[btn.dataset.menu]).hidden = false;
    });
});
document.querySelectorAll('.vs-back-btn[data-back]').forEach((btn) => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.vs-menu-panel').forEach((p) => (p.hidden = true));
        vsMenuMain.hidden = false;
    });
});

// --- 옵션(사운드 온오프·소리크기) — 값은 localStorage 저장만, 실제 오디오는 추후 연동 ---
const vsSound = document.getElementById('vs-opt-sound');
const vsVolume = document.getElementById('vs-opt-volume');
if (vsSound) {
    vsSound.checked = localStorage.getItem('vs_sound') !== '0';
    vsSound.addEventListener('change', () => localStorage.setItem('vs_sound', vsSound.checked ? '1' : '0'));
}
if (vsVolume) {
    vsVolume.value = localStorage.getItem('vs_volume') || '70';
    vsVolume.addEventListener('input', () => localStorage.setItem('vs_volume', vsVolume.value));
}

// --- 캐릭터 선택 → 게임 시작 ---
document.querySelectorAll('.vs-char-card[data-char]').forEach((card) => {
    card.addEventListener('click', function (e) {
        e.preventDefault();
        const scrollY = window.scrollY || window.pageYOffset;
        selectedChar = this.getAttribute('data-char');

        document.getElementById('startScreen').style.display = 'none';
        const gc = document.getElementById('game-container');
        gc.style.display = 'flex';
        gc.addEventListener('contextmenu', (ev) => ev.preventDefault(), false);
        window.scrollTo(0, scrollY);

        if (!game) game = new Phaser.Game(getPhaserConfig());
        setTimeout(() => window.scrollTo(0, scrollY), 10);
    });
});
})();
