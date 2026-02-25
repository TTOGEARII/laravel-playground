@extends('layouts.app')

@section('title', 'Otaku Shop - 가격비교 쇼핑몰')

@section('header')
    <span class="header-badge">🛒 Otaku Shop</span>
    <h1>오타쿠 굿즈 가격비교</h1>
    <p>여러 쇼핑몰의 가격과 혜택을 한눈에 비교해 보세요.</p>
@endsection

@section('content')
    <div class="otaku-shop-layout">
        {{-- 왼쪽 필터 영역 --}}
        <aside class="otaku-filter-panel">
            <div class="filter-section">
                <h2 class="filter-title">카테고리</h2>
                <ul class="filter-list">
                    <li><button class="filter-chip is-active">전체</button></li>
                    <li><button class="filter-chip">피규어</button></li>
                    <li><button class="filter-chip">굿즈</button></li>
                    <li><button class="filter-chip">블루레이 / DVD</button></li>
                    <li><button class="filter-chip">서적</button></li>
                </ul>
            </div>

            <div class="filter-section">
                <h2 class="filter-title">브랜드 / 샵</h2>
                <label class="filter-checkbox">
                    <input type="checkbox" checked>
                    <span>아마존 재팬</span>
                </label>
                <label class="filter-checkbox">
                    <input type="checkbox" checked>
                    <span>애니플렉스</span>
                </label>
                <label class="filter-checkbox">
                    <input type="checkbox">
                    <span>굿스마일</span>
                </label>
                <label class="filter-checkbox">
                    <input type="checkbox">
                    <span>토이즈러어스</span>
                </label>
            </div>

            <div class="filter-section">
                <h2 class="filter-title">가격 범위</h2>
                <div class="price-range">
                    <div class="price-inputs">
                        <div class="price-input">
                            <span>최소</span>
                            <input type="number" value="0">
                        </div>
                        <span class="price-separator">~</span>
                        <div class="price-input">
                            <span>최대</span>
                            <input type="number" value="200000">
                        </div>
                    </div>
                    <div class="price-hint">엔(¥) 기준 예시 값입니다.</div>
                </div>
            </div>

            <button class="filter-reset-button">필터 초기화</button>
        </aside>

        {{-- 오른쪽 리스트 / 비교 영역 --}}
        <section class="otaku-content">
            <div class="otaku-toolbar">
                <div class="search-box">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="search-icon">
                        <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M21 21l-4.35-4.35M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14z"/>
                    </svg>
                    <input type="text" placeholder="상품명, 작품명, 캐릭터명으로 검색">
                </div>

                <div class="toolbar-right">
                    <div class="sort-select">
                        <label for="sort">정렬</label>
                        <select id="sort">
                            <option>최저가 순</option>
                            <option>가격 높은 순</option>
                            <option>할인율 높은 순</option>
                            <option>발매일 최신 순</option>
                        </select>
                    </div>
                    <button class="compare-toggle-button">
                        비교함 보기
                        <span class="compare-badge">2</span>
                    </button>
                </div>
            </div>

            {{-- 비교 요약 바 --}}
            <div class="compare-summary-bar">
                <div class="compare-summary-info">
                    <span class="summary-label">비교 중인 상품</span>
                    <span class="summary-value">2개</span>
                    <span class="summary-divider">|</span>
                    <span class="summary-text">최저가 기준 최대 <strong>18,000원</strong> 차이</span>
                </div>
                <button class="summary-button">상세 비교 표 보기</button>
            </div>

            {{-- 상품 리스트 --}}
            <div class="product-list">
                {{-- 상품 카드 1 --}}
                <article class="product-card-row is-featured">
                    <div class="product-thumbnail">
                        <div class="thumb-image thumb-placeholder">FIG</div>
                    </div>
                    <div class="product-main">
                        <div class="product-meta">
                            <span class="badge badge-new">예약</span>
                            <span class="badge badge-brand">굿스마일</span>
                        </div>
                        <h2 class="product-title">[귀멸의 칼날] 카마도 탄지로 1/7 스케일 피규어</h2>
                        <p class="product-subtitle">굿스마일 × 애니플렉스 콜라보 한정판</p>
                        <div class="product-tags">
                            <span class="tag">피규어</span>
                            <span class="tag">1/7 스케일</span>
                            <span class="tag">국제 배송</span>
                        </div>
                        <div class="product-meta-detail">
                            <span>발매일: 2025.03 예정</span>
                            <span>포인트 적립: 최대 10%</span>
                        </div>
                    </div>
                    <div class="product-shops">
                        <div class="shop-row is-lowest">
                            <div class="shop-info">
                                <span class="shop-name">아마존 재팬</span>
                                <span class="shop-badge">최저가</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">¥12,800</div>
                                <div class="price-sub">약 115,000원 / 배송비 ¥1,000</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>

                        <div class="shop-row">
                            <div class="shop-info">
                                <span class="shop-name">애니플렉스</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">¥13,800</div>
                                <div class="price-sub">약 124,000원 / 무료 배송</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>

                        <div class="shop-row">
                            <div class="shop-info">
                                <span class="shop-name">국내샵 A</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">₩139,000</div>
                                <div class="price-sub">국내 배송 / 당일 출고</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>
                    </div>
                </article>

                {{-- 상품 카드 2 --}}
                <article class="product-card-row">
                    <div class="product-thumbnail">
                        <div class="thumb-image thumb-placeholder">BD</div>
                    </div>
                    <div class="product-main">
                        <div class="product-meta">
                            <span class="badge badge-hot">인기</span>
                            <span class="badge badge-brand">애니플렉스</span>
                        </div>
                        <h2 class="product-title">[소드 아트 온라인] 10주년 블루레이 박스</h2>
                        <p class="product-subtitle">본편 + 스페셜 북클릿 + OST 포함 한정판</p>
                        <div class="product-tags">
                            <span class="tag">블루레이</span>
                            <span class="tag">한정판</span>
                            <span class="tag">북클릿 포함</span>
                        </div>
                        <div class="product-meta-detail">
                            <span>발매일: 2024.11</span>
                            <span>재고: 넉넉</span>
                        </div>
                    </div>
                    <div class="product-shops">
                        <div class="shop-row is-lowest">
                            <div class="shop-info">
                                <span class="shop-name">애니플렉스 공식</span>
                                <span class="shop-badge">최저가</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">¥9,800</div>
                                <div class="price-sub">약 88,000원 / 무료 배송</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>

                        <div class="shop-row">
                            <div class="shop-info">
                                <span class="shop-name">국내샵 B</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">₩99,000</div>
                                <div class="price-sub">국내 배송 / 사은품 증정</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>
                    </div>
                </article>

                {{-- 상품 카드 3 --}}
                <article class="product-card-row">
                    <div class="product-thumbnail">
                        <div class="thumb-image thumb-placeholder">MD</div>
                    </div>
                    <div class="product-main">
                        <div class="product-meta">
                            <span class="badge badge-brand">공식 굿즈샵</span>
                        </div>
                        <h2 class="product-title">[원피스] 루피 일러스트 아크릴 스탠드 세트</h2>
                        <p class="product-subtitle">루피 & 조로 듀오 아크릴 스탠드 세트</p>
                        <div class="product-tags">
                            <span class="tag">굿즈</span>
                            <span class="tag">아크릴 스탠드</span>
                        </div>
                        <div class="product-meta-detail">
                            <span>발매일: 2024.09</span>
                            <span>재고: 한정 수량</span>
                        </div>
                    </div>
                    <div class="product-shops">
                        <div class="shop-row is-lowest">
                            <div class="shop-info">
                                <span class="shop-name">공식 굿즈샵</span>
                                <span class="shop-badge">최저가</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">₩29,000</div>
                                <div class="price-sub">국내 배송 / 무료 배송</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>

                        <div class="shop-row">
                            <div class="shop-info">
                                <span class="shop-name">국내샵 C</span>
                            </div>
                            <div class="shop-price">
                                <div class="price-main">₩32,000</div>
                                <div class="price-sub">국내 배송 / 적립금 5%</div>
                            </div>
                            <a href="#" class="shop-link">보러가기</a>
                        </div>
                    </div>
                </article>
            </div>

            {{-- 간단 비교 테이블 (샘플) --}}
            <div class="compare-table-wrapper">
                <h3 class="compare-title">빠른 가격 비교</h3>
                <div class="compare-table">
                    <div class="compare-row compare-header">
                        <div class="compare-cell">상품명</div>
                        <div class="compare-cell">최저가</div>
                        <div class="compare-cell">평균가</div>
                        <div class="compare-cell">최고가</div>
                        <div class="compare-cell">가격 차이</div>
                    </div>
                    <div class="compare-row">
                        <div class="compare-cell compare-name">귀멸의 칼날 탄지로 1/7</div>
                        <div class="compare-cell">¥12,800</div>
                        <div class="compare-cell">¥13,900</div>
                        <div class="compare-cell">₩139,000</div>
                        <div class="compare-cell compare-diff positive">+ 약 24,000원</div>
                    </div>
                    <div class="compare-row">
                        <div class="compare-cell compare-name">SAO 10주년 블루레이</div>
                        <div class="compare-cell">¥9,800</div>
                        <div class="compare-cell">¥10,200</div>
                        <div class="compare-cell">₩99,000</div>
                        <div class="compare-cell compare-diff positive">+ 약 11,000원</div>
                    </div>
                    <div class="compare-row">
                        <div class="compare-cell compare-name">원피스 아크릴 스탠드</div>
                        <div class="compare-cell">₩29,000</div>
                        <div class="compare-cell">₩30,500</div>
                        <div class="compare-cell">₩32,000</div>
                        <div class="compare-cell compare-diff neutral">+ 3,000원</div>
                    </div>
                </div>
            </div>
        </section>
    </div>
@endsection