{{--
    사이트 공통 푸터. 개인 포트폴리오·비영리 플레이그라운드라는 성격과
    외부(쇼핑몰·게임 공식/팬 사이트) 데이터 수집에 대한 저작권 고지를 담는다.
--}}
<footer class="footer site-footer">
    <div class="site-footer-inner">
        <p class="site-footer-brand">
            Laravel Playland
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

        <p class="site-footer-made">
            Made with <span class="footer-heart">❤️</span> by TTOGEARII
        </p>
    </div>
</footer>
