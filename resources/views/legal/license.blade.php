@extends('layouts.app')

@section('title', '라이센스 | Kanenashi Togeari')

@section('body-class', 'legal-page')

@section('content')
    <x-legal-document title="라이센스" :updated="$updated">
        <p class="legal-lead">
            <strong>Kanenashi Togeari</strong>는 개인 학습·포트폴리오 목적의 비영리 사이트입니다.
            사이트에서 다루는 게임·상품 데이터의 저작권은 각 권리자에게 있으며, 아래에 출처와 사용 라이브러리를 밝힙니다.
        </p>

        <h2>게임 저작권 (Game Copyrights)</h2>
        <p>각 게임의 명칭·캐릭터·이미지·리소스에 대한 저작권은 해당 개발사/퍼블리셔에 있습니다. 본 사이트는 이들과 무관한 비공식 팬 사이트입니다.</p>
        <ul>
            <li>원신, 붕괴: 스타레일, 젠레스 존 제로 — © HoYoverse (COGNOSPHERE)</li>
            <li>블루 아카이브 — © NEXON Games / Yostar</li>
            <li>명조: 워더링 웨이브 — © Kuro Games</li>
            <li>승리의 여신: 니케 — © Shift Up / Level Infinite</li>
            <li>브라운더스트2 — © Neowiz</li>
            <li>트릭컬 리바이브 — © EPID Games</li>
        </ul>

        <h2>상품·이미지 (OtakuShop)</h2>
        <p>
            가격비교에 표시되는 상품명·이미지·제조사 정보의 저작권은 각 제조사 및 판매 쇼핑몰에 있습니다.
            데이터는 정보 제공 목적의 자동 수집물이며, 영리적으로 이용하지 않습니다.
        </p>
        <ul>
            <li>수집 출처: 도키도키굿즈, 애니메이트코리아, 따빼몰, 굿스마일코리아, 코믹스아트, 피규어프레소</li>
        </ul>

        <h2>리딤코드 정보 출처 (SubcultureGameInfo)</h2>
        <ul>
            <li>게임사 공식 채널 및 비공식 집계 API(호요버스 코드 집계 등)</li>
            <li>코드 정리 커뮤니티/사이트, 디씨인사이드·아카라이브 등 커뮤니티(교차검증 용도)</li>
            <li>네이버 게임 라운지(게임사 공식 게시판)</li>
        </ul>

        <h2>오픈소스 라이브러리 (Open Source)</h2>
        <p>본 사이트는 다음 오픈소스 소프트웨어를 사용하며, 각 저작권과 라이센스는 원저작자에게 있습니다.</p>
        <ul>
            <li>Laravel (MIT) — 백엔드 프레임워크</li>
            <li>Vue 3 (MIT) — 프론트엔드 UI</li>
            <li>Tailwind CSS (MIT) — 스타일링</li>
            <li>Phaser (MIT) — 미니게임 엔진</li>
            <li>Axios (MIT) — HTTP 클라이언트</li>
            <li>Google Fonts — Outfit, Noto Sans KR (Open Font License)</li>
        </ul>

        <p class="legal-foot">
            저작권 관련 문제나 게시 중단 요청이 있으시면 <a href="{{ route('inquiry.create') }}">문의하기</a>로 연락 주시면
            신속히 확인·조치하겠습니다.
        </p>
    </x-legal-document>
@endsection
