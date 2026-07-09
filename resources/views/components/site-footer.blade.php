{{--
    사이트 공통 푸터. 개인 포트폴리오·비영리 플레이그라운드라는 성격과
    외부(쇼핑몰·게임 공식/팬 사이트) 데이터 수집에 대한 저작권 고지를 담는다.
--}}
<footer class="footer site-footer">
    <div class="site-footer-inner">
        <p class="site-footer-brand">
            Kanenashi Togeari
            <span class="site-footer-sep">·</span>
            개인 포트폴리오 플레이그라운드
        </p>

        <p class="site-footer-copy">
            Copyright © 2025–2026 TTOGEARII. All Rights Reserved.
        </p>

        <p class="site-footer-disclaimer">
            본 사이트는 개인 학습·포트폴리오 목적의 <strong>비영리 프로젝트</strong>입니다.
            오타쿠샵 가격 정보, 게임 리딤코드 등은 외부 쇼핑몰과 게임 공식/팬 사이트에서 수집한 데이터이며,
            각 게임·상품·이미지의 저작권은 원저작자에게 있습니다.
            Shift Up, HoYoverse, Nexon, Kuro Games, EPID Games 등 관련 기업과 무관합니다.
        </p>

        <nav class="site-footer-links" aria-label="사이트 링크">
            <a href="{{ route('legal.terms') }}">이용약관</a>
            <span class="site-footer-sep">|</span>
            <a href="{{ route('legal.privacy') }}">개인정보처리방침</a>
            <span class="site-footer-sep">|</span>
            <a href="{{ route('legal.license') }}">라이센스</a>
            <span class="site-footer-sep">|</span>
            <a href="{{ route('inquiry.create') }}">문의하기</a>
        </nav>

        {{-- PWA 앱 설치 — 크롬/안드로이드는 설치 프롬프트 직접 호출, 그 외(iOS 등)는 설치 방법 안내.
             앱(standalone)으로 이미 실행 중이면 표시하지 않는다. --}}
        <div class="site-footer-install" id="pwa-install" hidden>
            <button type="button" id="pwa-install-btn" class="site-footer-install-btn">
                📱 홈 화면에 앱으로 설치
            </button>
            <p class="site-footer-install-hint" id="pwa-install-hint" hidden>
                아이폰: 사파리 공유(<span aria-hidden="true">&#x2B06;&#xFE0E;</span>) → "홈 화면에 추가" ·
                안드로이드: 크롬 메뉴(⋮) → "앱 설치"
            </p>
        </div>
        <script>
            (function () {
                var box = document.getElementById('pwa-install');
                var btn = document.getElementById('pwa-install-btn');
                var hint = document.getElementById('pwa-install-hint');
                if (!box || !btn) return;

                // 이미 앱으로 실행 중이면 설치 링크 불필요
                var standalone = window.matchMedia('(display-mode: standalone)').matches
                    || window.navigator.standalone === true;
                if (standalone) return;

                box.hidden = false;

                var deferredPrompt = null;
                window.addEventListener('beforeinstallprompt', function (e) {
                    e.preventDefault(); // 브라우저 기본 배너 대신 푸터 버튼으로 트리거
                    deferredPrompt = e;
                });

                btn.addEventListener('click', function () {
                    if (deferredPrompt) {
                        deferredPrompt.prompt();
                        deferredPrompt.userChoice.then(function (choice) {
                            if (choice.outcome === 'accepted') box.hidden = true;
                            deferredPrompt = null;
                        });
                    } else if (hint) {
                        // 프롬프트 미지원(iOS 사파리 등) → 설치 방법 토글
                        hint.hidden = !hint.hidden;
                    }
                });

                window.addEventListener('appinstalled', function () { box.hidden = true; });
            })();
        </script>

        <p class="site-footer-made">
            Made with <span class="footer-heart">❤️</span> by TTOGEARII
        </p>
    </div>
</footer>
