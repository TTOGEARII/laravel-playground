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
            <li>수집 출처(국내): 도키도키굿즈, 애니메이트코리아, 따빼몰, 굿스마일코리아, 코믹스아트, 피규어프레소</li>
            <li>수집 출처(해외관): 아미아미(AmiAmi) — 가격은 엔화 원가이며 원화 환산은 참고용</li>
        </ul>

        <h2>서브컬쳐 게임 정보 출처 (SubcultureGameInfo)</h2>
        <p>
            정보검색·리딤코드·AI 에이전트에 표시되는 데이터는 아래 공개 출처에서 정보 제공 목적으로 자동 수집·인용하며,
            각 데이터의 저작권은 게임사 및 원 사이트에 있습니다. 영리적으로 이용하지 않습니다.
        </p>
        <ul>
            <li><strong>리딤코드</strong>: 게임사 공식 채널·비공식 집계 API, 코드 정리 사이트, 디씨인사이드·아카라이브 등 커뮤니티(교차검증), 네이버 게임 라운지</li>
            <li><strong>캐릭터·도감·일정</strong>: SchaleDB, 몰루로그(mollulog.net), Project Yatta, Enka.Network, HoYoLAB 위키(호요랩 공식 위키)</li>
            <li><strong>캐릭터 빌드(티어·무기·세트·재료)</strong>: wuthering.gg(명조), genshin-builds.com·zzz.gg(호요버스)</li>
            <li><strong>레이드 편성·공략·조합</strong>: 몰루로그·레츠도로·baql.net(랭킹), 트릭컬 레코드/팀 매니저, 디씨·아카 공략글, YouTube 검색(조합 영상)</li>
        </ul>

        <h2>행사 캘린더 출처 (EventCalendar)</h2>
        <p>
            행사 캘린더에 표시되는 공연·행사 일정은 아래 공개 출처에서 정보 제공 목적으로 자동 수집·인용하며,
            각 정보의 저작권은 주최사 및 원 사이트에 있습니다. 정확한 일정·가격은 각 공식 페이지 기준입니다.
        </p>
        <ul>
            <li><strong>내한공연</strong>: 페스티벌라이프(festivallife.kr), 짱짱이의 짱짱한 일상 — 일본 가수 내한 공연 캘린더(j-pop-playlist.tistory.com, CC BY-NC-ND)</li>
            <li><strong>서브컬쳐 행사</strong>: 코믹월드(comicw.net), 일러스타페스(illustar.net), AGF(agfkorea.com), 킨텍스·SETEC·코엑스 행사 캘린더, 네이버 게임 라운지 공지</li>
        </ul>

        <h2>오픈소스 라이브러리 (Open Source)</h2>
        <p>본 사이트는 다음 오픈소스 소프트웨어를 사용하며, 각 저작권과 라이센스는 원저작자에게 있습니다.</p>
        <ul>
            <li>Laravel (MIT) — 백엔드 프레임워크</li>
            <li>Vue 3 (MIT) — 프론트엔드 UI</li>
            <li>Tailwind CSS (MIT) — 스타일링</li>
            <li>Phaser (MIT) — 미니게임 엔진</li>
            <li>Axios (MIT) — HTTP 클라이언트</li>
            <li>Prism PHP (MIT) — AI 에이전트 LLM 연동</li>
            <li>markdown-it (MIT) — AI 채팅 답변 렌더링</li>
            <li>Playwright (Apache-2.0) — 레이드 정보 수집(크롤링) 사이드카</li>
            <li>Google Fonts — Figtree, Noto Sans KR (Open Font License)</li>
        </ul>

        <p class="legal-foot">
            저작권 관련 문제나 게시 중단 요청이 있으시면 <a href="{{ route('inquiry.create') }}">문의하기</a>로 연락 주시면
            신속히 확인·조치하겠습니다.
        </p>
    </x-legal-document>
@endsection
