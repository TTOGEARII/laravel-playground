# 개인 포트폴리오

Laravel 기반 개인 포트폴리오 프로젝트입니다.

## 요구사항

- Docker Desktop
- PHP 8.2
- Laravel 12

## 사용 서비스

- MariaDB
- Redis (캐시, 세션, 큐)

## Sail 명령어

```bash
# 실행
./vendor/bin/sail up -d

# 중지
./vendor/bin/sail down

# 로그 확인
./vendor/bin/sail logs -f

# 컨테이너 상태 확인
./vendor/bin/sail ps
```

## Artisan 명령어

### 컨트롤러 생성

```bash
# 섹션별 컨트롤러 생성
./vendor/bin/sail artisan make:controller Admin/UserController
./vendor/bin/sail artisan make:controller Admin/ProductController
./vendor/bin/sail artisan make:controller Front/HomeController
./vendor/bin/sail artisan make:controller Api/OrderController
```

### 모델 생성

```bash
# 섹션별 모델 생성 (-m: 마이그레이션 포함)
./vendor/bin/sail artisan make:model User/User -m
./vendor/bin/sail artisan make:model Shop/Product -m
./vendor/bin/sail artisan make:model Shop/Order -m
```

### 기타 명령어

```bash
# 마이그레이션
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:fresh --seed

# 캐시 클리어
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear

# Tinker (REPL)
./vendor/bin/sail artisan tinker
```