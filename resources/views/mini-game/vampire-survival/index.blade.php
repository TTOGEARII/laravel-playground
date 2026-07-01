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
        <div class="game-start-screen" id="startScreen">
            <div class="start-screen-content">
                <h2>🧛 뱀파이어 서바이벌</h2>
                <p>마지막까지 살아남아보거라!</p>
                <button id="startGameBtn" class="start-game-button">게임 시작</button>
            </div>
        </div>
        <div id="game-container" style="display: none;" tabindex="0" title="터치하여 이동"></div>
    </div>

    <x-mini-game.ranking-overlay game="vampire-survival" />

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/phaser@3.80.1/dist/phaser.min.js"></script>
    <script>
// ============================================================================
// 게임 설정 (모바일 시 동적 해상도)
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
    get WIDTH() { return getGameSize().width; },
    get HEIGHT() { return getGameSize().height; },
    PLAYER_SPEED: 200,
    PLAYER_HP: 100,
    ENEMY_BASE_SPEED: 80,
    ENEMY_BASE_HP: 30,
    ENEMY_DAMAGE: 10,
    ATTACK_COOLDOWN: 500,
    ATTACK_RANGE: 150,
    ATTACK_DAMAGE: 25,
    XP_TO_LEVEL: 100,
    SPAWN_INTERVAL: 1500,
    MAX_ENEMIES: 50
};

// ============================================================================
// 메인 게임 씬
// ============================================================================
class GameScene extends Phaser.Scene {
    constructor() {
        super({ key: 'GameScene' });
    }

    init() {
        // 게임 상태 초기화
        this.playerHP = CONFIG.PLAYER_HP;
        this.maxHP = CONFIG.PLAYER_HP;
        this.level = 1;
        this.xp = 0;
        this.xpToNext = CONFIG.XP_TO_LEVEL;
        this.kills = 0;
        this.gameTime = 0;
        this.isGameOver = false;
        this.attackCooldown = 0;
        this.attackDamage = CONFIG.ATTACK_DAMAGE;
        this.attackRange = CONFIG.ATTACK_RANGE;
        this.playerSpeed = CONFIG.PLAYER_SPEED;
    }

    create() {
        // 월드 바운드 설정 (맵 크기)
        this.physics.world.setBounds(-1000, -1000, 3000, 3000);

        // 배경 생성
        this.createBackground();

        // 플레이어 생성
        this.createPlayer();

        // 적 그룹 생성
        this.enemies = this.physics.add.group();

        // 경험치 오브 그룹
        this.xpOrbs = this.physics.add.group();

        // 공격 이펙트 그룹
        this.attackEffects = this.add.group();

        // 충돌 설정
        this.physics.add.overlap(this.player, this.enemies, this.onPlayerHit, null, this);
        this.physics.add.overlap(this.player, this.xpOrbs, this.collectXP, null, this);

        // 입력 설정 (키보드 + 모바일 터치)
        this.cursors = this.input.keyboard.createCursorKeys();
        this.wasd = this.input.keyboard.addKeys({
            up: Phaser.Input.Keyboard.KeyCodes.W,
            down: Phaser.Input.Keyboard.KeyCodes.S,
            left: Phaser.Input.Keyboard.KeyCodes.A,
            right: Phaser.Input.Keyboard.KeyCodes.D
        });
        this.touchMoveActive = false;
        this.input.on('pointerdown', this.onPointerDown, this);
        this.input.on('pointerup', this.onPointerUp, this);
        this.input.on('pointerout', this.onPointerUp, this);

        // UI 생성
        this.createUI();

        // 적 스폰 타이머
        this.spawnTimer = this.time.addEvent({
            delay: CONFIG.SPAWN_INTERVAL,
            callback: this.spawnEnemy,
            callbackScope: this,
            loop: true
        });

        // 게임 시간 타이머
        this.time.addEvent({
            delay: 1000,
            callback: () => { if (!this.isGameOver) this.gameTime++; },
            loop: true
        });

        // 카메라 설정
        this.cameras.main.startFollow(this.player, true, 0.1, 0.1);
        this.cameras.main.setZoom(1);
    }

    createBackground() {
        // 타일 패턴 배경
        const graphics = this.add.graphics();
        const tileSize = 64;
        
        for (let x = -1000; x < 2000; x += tileSize) {
            for (let y = -1000; y < 2000; y += tileSize) {
                const shade = ((x + y) / tileSize) % 2 === 0 ? 0x16213e : 0x1a1a2e;
                graphics.fillStyle(shade);
                graphics.fillRect(x, y, tileSize, tileSize);
            }
        }
    }

    createPlayer() {
        // 플레이어 그래픽 생성
        const playerGraphics = this.add.graphics();
        playerGraphics.fillStyle(0x4ecca3);
        playerGraphics.fillCircle(25, 25, 20);
        playerGraphics.fillStyle(0xeeeeee);
        playerGraphics.fillCircle(30, 20, 5);
        playerGraphics.fillCircle(20, 20, 5);
        playerGraphics.generateTexture('player', 50, 50);
        playerGraphics.destroy();

        const w = this.scale.width, h = this.scale.height;
        this.player = this.physics.add.sprite(w / 2, h / 2, 'player');
        this.player.setCollideWorldBounds(true);
        this.player.setDepth(10);
        this.player.body.setCircle(20, 5, 5);

        // 공격 범위 표시 (반투명 원)
        this.attackRangeCircle = this.add.graphics();
        this.attackRangeCircle.setDepth(5);
    }

    createUI() {
        // UI는 카메라를 따라가도록 설정
        this.uiContainer = this.add.container(0, 0);
        this.uiContainer.setScrollFactor(0);
        this.uiContainer.setDepth(100);

        // HP 바 배경
        const hpBarBg = this.add.graphics();
        hpBarBg.fillStyle(0x333333);
        hpBarBg.fillRoundedRect(20, 20, 200, 25, 5);
        this.uiContainer.add(hpBarBg);

        // HP 바
        this.hpBar = this.add.graphics();
        this.uiContainer.add(this.hpBar);
        this.updateHPBar();

        // XP 바 배경
        const xpBarBg = this.add.graphics();
        xpBarBg.fillStyle(0x333333);
        xpBarBg.fillRoundedRect(20, 50, 200, 15, 3);
        this.uiContainer.add(xpBarBg);

        // XP 바
        this.xpBar = this.add.graphics();
        this.uiContainer.add(this.xpBar);
        this.updateXPBar();

        // 텍스트 스타일
        const textStyle = {
            fontSize: '18px',
            fill: '#ffffff',
            stroke: '#000000',
            strokeThickness: 3
        };

        // 레벨 텍스트
        this.levelText = this.add.text(20, 70, 'Lv. 1', textStyle);
        this.uiContainer.add(this.levelText);

        const gw = this.scale.width, gh = this.scale.height;
        // 시간 텍스트
        this.timeText = this.add.text(gw - 100, 20, '00:00', textStyle);
        this.uiContainer.add(this.timeText);

        // 킬 카운트
        this.killText = this.add.text(gw - 100, 45, 'Kills: 0', textStyle);
        this.uiContainer.add(this.killText);

        // 조작 안내 (모바일은 터치 안내)
        const helpMsg = isMobile() ? '화면 터치로 이동 | 자동 공격' : 'WASD / 방향키로 이동 | 자동 공격';
        const helpText = this.add.text(gw / 2, gh - 30, helpMsg, { fontSize: '14px', fill: '#aaaaaa' });
        helpText.setOrigin(0.5);
        this.uiContainer.add(helpText);
    }

    onPointerDown() {
        this.touchMoveActive = true;
    }

    onPointerUp() {
        this.touchMoveActive = false;
    }

    updateHPBar() {
        this.hpBar.clear();
        const hpPercent = this.playerHP / this.maxHP;
        const color = hpPercent > 0.5 ? 0x4ecca3 : (hpPercent > 0.25 ? 0xf9ed69 : 0xe94560);
        this.hpBar.fillStyle(color);
        this.hpBar.fillRoundedRect(20, 20, 200 * hpPercent, 25, 5);
    }

    updateXPBar() {
        this.xpBar.clear();
        this.xpBar.fillStyle(0x7b68ee);
        this.xpBar.fillRoundedRect(20, 50, 200 * (this.xp / this.xpToNext), 15, 3);
    }

    spawnEnemy() {
        if (this.isGameOver || this.enemies.countActive() >= CONFIG.MAX_ENEMIES) return;

        // 플레이어 주변 원형으로 스폰
        const angle = Phaser.Math.FloatBetween(0, Math.PI * 2);
        const distance = Phaser.Math.Between(400, 600);
        const x = this.player.x + Math.cos(angle) * distance;
        const y = this.player.y + Math.sin(angle) * distance;

        // 적 종류 결정 (레벨에 따라 다양화)
        const enemyType = this.getEnemyType();
        
        // 적 그래픽 생성
        const enemyKey = `enemy_${enemyType.color.toString(16)}`;
        if (!this.textures.exists(enemyKey)) {
            const g = this.add.graphics();
            g.fillStyle(enemyType.color);
            g.fillCircle(15, 15, enemyType.size);
            g.generateTexture(enemyKey, 30, 30);
            g.destroy();
        }

        const enemy = this.enemies.create(x, y, enemyKey);
        enemy.setData('hp', enemyType.hp);
        enemy.setData('damage', enemyType.damage);
        enemy.setData('speed', enemyType.speed);
        enemy.setData('xp', enemyType.xp);
        enemy.body.setCircle(enemyType.size, 15 - enemyType.size, 15 - enemyType.size);
    }

    getEnemyType() {
        const baseMultiplier = 1 + (this.level - 1) * 0.1 + (this.gameTime / 60) * 0.1;
        
        const types = [
            { // 일반
                color: 0xe94560,
                size: 12,
                hp: Math.floor(CONFIG.ENEMY_BASE_HP * baseMultiplier),
                damage: CONFIG.ENEMY_DAMAGE,
                speed: CONFIG.ENEMY_BASE_SPEED * baseMultiplier,
                xp: 10
            },
            { // 빠른 적
                color: 0xf9ed69,
                size: 8,
                hp: Math.floor(CONFIG.ENEMY_BASE_HP * 0.5 * baseMultiplier),
                damage: CONFIG.ENEMY_DAMAGE * 0.5,
                speed: CONFIG.ENEMY_BASE_SPEED * 1.5 * baseMultiplier,
                xp: 15
            },
            { // 탱커
                color: 0x6a0572,
                size: 18,
                hp: Math.floor(CONFIG.ENEMY_BASE_HP * 2 * baseMultiplier),
                damage: CONFIG.ENEMY_DAMAGE * 1.5,
                speed: CONFIG.ENEMY_BASE_SPEED * 0.6 * baseMultiplier,
                xp: 25
            }
        ];

        // 레벨이 높을수록 강한 적 등장 확률 증가
        const weights = [60, 25, 15];
        if (this.level >= 3) { weights[1] += 10; weights[2] += 5; weights[0] -= 15; }
        if (this.level >= 5) { weights[1] += 10; weights[2] += 10; weights[0] -= 20; }

        const roll = Phaser.Math.Between(1, 100);
        if (roll <= weights[0]) return types[0];
        if (roll <= weights[0] + weights[1]) return types[1];
        return types[2];
    }

    update(time, delta) {
        if (this.isGameOver) return;

        // 플레이어 이동
        this.handlePlayerMovement();

        // 적 AI
        this.updateEnemies();

        // 자동 공격
        this.attackCooldown -= delta;
        if (this.attackCooldown <= 0) {
            this.performAttack();
            this.attackCooldown = CONFIG.ATTACK_COOLDOWN;
        }

        // 공격 범위 표시 업데이트
        this.updateAttackRangeVisual();

        // UI 업데이트
        this.updateTimeDisplay();
    }

    handlePlayerMovement() {
        let vx = 0;
        let vy = 0;

        // 터치/마우스: 누른 위치 방향으로 이동
        if (this.touchMoveActive && this.input.activePointer.isDown) {
            const world = this.cameras.main.getWorldPoint(this.input.activePointer.x, this.input.activePointer.y);
            const dx = world.x - this.player.x;
            const dy = world.y - this.player.y;
            const dist = Math.sqrt(dx * dx + dy * dy);
            if (dist > 20) {
                vx = dx / dist;
                vy = dy / dist;
            }
        } else {
            if (this.cursors.left.isDown || this.wasd.left.isDown) vx = -1;
            else if (this.cursors.right.isDown || this.wasd.right.isDown) vx = 1;
            if (this.cursors.up.isDown || this.wasd.up.isDown) vy = -1;
            else if (this.cursors.down.isDown || this.wasd.down.isDown) vy = 1;
            // 대각선 이동 시 속도 정규화
            if (vx !== 0 && vy !== 0) {
                vx *= 0.707;
                vy *= 0.707;
            }
        }

        this.player.setVelocity(vx * this.playerSpeed, vy * this.playerSpeed);
    }

    updateEnemies() {
        this.enemies.children.iterate((enemy) => {
            if (!enemy || !enemy.active) return;

            // 플레이어를 향해 이동
            const angle = Phaser.Math.Angle.Between(
                enemy.x, enemy.y,
                this.player.x, this.player.y
            );
            const speed = enemy.getData('speed');
            enemy.setVelocity(
                Math.cos(angle) * speed,
                Math.sin(angle) * speed
            );
        });
    }

    performAttack() {
        let closestEnemy = null;
        let closestDist = this.attackRange;

        // 범위 내 가장 가까운 적 찾기
        this.enemies.children.iterate((enemy) => {
            if (!enemy || !enemy.active) return;

            const dist = Phaser.Math.Distance.Between(
                this.player.x, this.player.y,
                enemy.x, enemy.y
            );

            if (dist < closestDist) {
                closestDist = dist;
                closestEnemy = enemy;
            }
        });

        if (closestEnemy) {
            this.attackEnemy(closestEnemy);
        }
    }

    attackEnemy(enemy) {
        // 공격 이펙트 (선)
        const line = this.add.graphics();
        line.lineStyle(3, 0x4ecca3, 1);
        line.beginPath();
        line.moveTo(this.player.x, this.player.y);
        line.lineTo(enemy.x, enemy.y);
        line.strokePath();

        // 이펙트 페이드 아웃
        this.tweens.add({
            targets: line,
            alpha: 0,
            duration: 150,
            onComplete: () => line.destroy()
        });

        // 데미지 적용
        const hp = enemy.getData('hp') - this.attackDamage;
        enemy.setData('hp', hp);

        // 피격 이펙트
        this.tweens.add({
            targets: enemy,
            tint: 0xffffff,
            duration: 50,
            yoyo: true
        });

        if (hp <= 0) {
            this.killEnemy(enemy);
        }
    }

    killEnemy(enemy) {
        const xpValue = enemy.getData('xp');
        
        // 경험치 오브 생성
        this.createXPOrb(enemy.x, enemy.y, xpValue);

        // 사망 이펙트
        const particles = this.add.graphics();
        particles.fillStyle(0xe94560);
        for (let i = 0; i < 5; i++) {
            const px = enemy.x + Phaser.Math.Between(-10, 10);
            const py = enemy.y + Phaser.Math.Between(-10, 10);
            particles.fillCircle(px, py, 3);
        }
        this.tweens.add({
            targets: particles,
            alpha: 0,
            duration: 300,
            onComplete: () => particles.destroy()
        });

        enemy.destroy();
        this.kills++;
        this.killText.setText(`Kills: ${this.kills}`);
    }

    createXPOrb(x, y, value) {
        // XP 오브 그래픽
        if (!this.textures.exists('xp_orb')) {
            const g = this.add.graphics();
            g.fillStyle(0x7b68ee);
            g.fillCircle(8, 8, 6);
            g.fillStyle(0xaaaaff);
            g.fillCircle(6, 6, 2);
            g.generateTexture('xp_orb', 16, 16);
            g.destroy();
        }

        const orb = this.xpOrbs.create(x, y, 'xp_orb');
        orb.setData('value', value);
        
        // 약간 튀어오르는 효과
        this.tweens.add({
            targets: orb,
            y: y - 20,
            duration: 200,
            yoyo: true,
            ease: 'Quad.easeOut'
        });
    }

    collectXP(player, orb) {
        const value = orb.getData('value');
        this.xp += value;
        orb.destroy();

        // 레벨업 체크
        while (this.xp >= this.xpToNext) {
            this.xp -= this.xpToNext;
            this.levelUp();
        }

        this.updateXPBar();
    }

    levelUp() {
        this.level++;
        this.xpToNext = Math.floor(CONFIG.XP_TO_LEVEL * (1 + (this.level - 1) * 0.5));
        this.levelText.setText(`Lv. ${this.level}`);

        // 스탯 강화
        this.attackDamage += 5;
        this.attackRange += 10;
        this.maxHP += 10;
        this.playerHP = Math.min(this.playerHP + 20, this.maxHP);
        this.playerSpeed += 5;

        this.updateHPBar();

        // 레벨업 이펙트
        const levelUpText = this.add.text(this.player.x, this.player.y - 50, 'LEVEL UP!', {
            fontSize: '24px',
            fill: '#f9ed69',
            stroke: '#000000',
            strokeThickness: 4
        });
        levelUpText.setOrigin(0.5);
        levelUpText.setDepth(100);

        this.tweens.add({
            targets: levelUpText,
            y: this.player.y - 100,
            alpha: 0,
            duration: 1000,
            onComplete: () => levelUpText.destroy()
        });

        // 전체 화면 플래시
        this.cameras.main.flash(200, 249, 237, 105);
    }

    updateAttackRangeVisual() {
        this.attackRangeCircle.clear();
        this.attackRangeCircle.lineStyle(2, 0x4ecca3, 0.3);
        this.attackRangeCircle.strokeCircle(this.player.x, this.player.y, this.attackRange);
    }

    onPlayerHit(player, enemy) {
        const damage = enemy.getData('damage');
        this.playerHP -= damage * 0.016; // 프레임당 데미지

        this.updateHPBar();

        // 화면 흔들림
        if (Math.random() < 0.1) {
            this.cameras.main.shake(100, 0.005);
        }

        if (this.playerHP <= 0) {
            this.gameOver();
        }
    }

    updateTimeDisplay() {
        const minutes = Math.floor(this.gameTime / 60).toString().padStart(2, '0');
        const seconds = (this.gameTime % 60).toString().padStart(2, '0');
        this.timeText.setText(`${minutes}:${seconds}`);
    }

    gameOver() {
        this.isGameOver = true;
        this.physics.pause();

        const gw = this.scale.width, gh = this.scale.height;
        // 게임 오버 화면
        const overlay = this.add.graphics();
        overlay.fillStyle(0x000000, 0.7);
        overlay.fillRect(this.cameras.main.scrollX, this.cameras.main.scrollY, gw, gh);
        overlay.setDepth(200);

        const centerX = this.cameras.main.scrollX + gw / 2;
        const centerY = this.cameras.main.scrollY + gh / 2;

        const gameOverText = this.add.text(centerX, centerY - 80, 'GAME OVER', {
            fontSize: '48px',
            fill: '#e94560',
            stroke: '#000000',
            strokeThickness: 6
        });
        gameOverText.setOrigin(0.5);
        gameOverText.setDepth(201);

        const statsText = this.add.text(centerX, centerY, 
            `생존 시간: ${Math.floor(this.gameTime / 60)}분 ${this.gameTime % 60}초\n` +
            `레벨: ${this.level}\n` +
            `처치 수: ${this.kills}`, {
            fontSize: '24px',
            fill: '#ffffff',
            stroke: '#000000',
            strokeThickness: 3,
            align: 'center'
        });
        statsText.setOrigin(0.5);
        statsText.setDepth(201);

        const restartText = this.add.text(centerX, centerY + 100, '클릭하여 재시작', {
            fontSize: '20px',
            fill: '#4ecca3'
        });
        restartText.setOrigin(0.5);
        restartText.setDepth(201);

        // 깜빡임 효과
        this.tweens.add({
            targets: restartText,
            alpha: 0.3,
            duration: 500,
            yoyo: true,
            repeat: -1
        });

        // 랭킹 오버레이 표시. 점수 = 생존초×10 + 처치×2 (오래 살수록·많이 잡을수록 높음)
        window.MiniGameRanking?.show(this.gameTime * 10 + this.kills * 2);

        // 재시작
        this.input.once('pointerdown', () => {
            // 게임 인스턴스 파괴
            if (game) {
                game.destroy(true);
                game = null;
            }
            
            // 시작 화면으로 돌아가기
            const startScreen = document.getElementById('startScreen');
            const gameContainer = document.getElementById('game-container');
            const startBtn = document.getElementById('startGameBtn');
            
            startScreen.style.display = 'flex';
            gameContainer.style.display = 'none';
            startBtn.disabled = false;
            startBtn.textContent = '게임 시작';
        });
    }
}

// ============================================================================
// 게임 설정 및 시작
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
        scale: {
            mode: Phaser.Scale.FIT,
            autoCenter: Phaser.Scale.CENTER_BOTH
        },
        physics: {
            default: 'arcade',
            arcade: { debug: false }
        },
        scene: [GameScene]
    };
}

// 게임 시작 버튼 이벤트
const startBtn = document.getElementById('startGameBtn');
if (startBtn) {
    startBtn.addEventListener('click', function(e) {
        // 기본 동작 방지 (스크롤 이동 방지)
        e.preventDefault();
        e.stopPropagation();
        
        // 현재 스크롤 위치 저장
        const scrollY = window.scrollY || window.pageYOffset;
        
        // 시작 화면 숨기기
        const startScreen = document.getElementById('startScreen');
        const gameContainer = document.getElementById('game-container');
        
        startScreen.style.display = 'none';
        gameContainer.style.display = 'flex';
        gameContainer.addEventListener('contextmenu', function(e) { e.preventDefault(); }, false);
        
        // 스크롤 위치 유지
        window.scrollTo(0, scrollY);
        
        // 게임 시작 (매번 현재 화면 크기로 생성)
        if (!game) {
            game = new Phaser.Game(getPhaserConfig());
        }
        
        // 버튼 비활성화
        this.disabled = true;
        this.textContent = '게임 진행 중...';
        
        // 추가로 스크롤 위치 고정 (약간의 지연 후)
        setTimeout(() => {
            window.scrollTo(0, scrollY);
        }, 10);
    });
}
    </script>
    @endpush

    <div class="game-instructions">
        <h3>게임 방법</h3>
        <ul>
            <li class="desktop-only">WASD 또는 방향키로 캐릭터를 이동하세요</li>
            <li class="mobile-only">화면을 터치한 방향으로 캐릭터가 이동합니다</li>
            <li>적들이 자동으로 스폰되며 플레이어를 향해 이동합니다</li>
            <li>자동 공격으로 적을 처치하고 경험치를 획득하세요</li>
            <li>경험치를 모아 레벨업하면 공격력, 체력, 속도가 증가합니다</li>
            <li>가능한 오래 생존하여 높은 점수를 달성하세요!</li>
        </ul>
    </div>
@endsection
