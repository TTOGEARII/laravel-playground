<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 오퍼 동일성 기준을 (상품, 샵) → (샵, external_id)로 전환한다.
 *
 * 정규화 키(상품)는 매칭 사전 변경에 따라 달라져, 키를 식별자로 쓰면 같은 listing이 새 오퍼로
 * 중복 생성되고 옛 오퍼가 '사라짐=품절'로 오인됐다. 샵 내부 상품ID(external_id)는 회차 간
 * 불변이므로 이를 유니크 키로 삼아 안정적인 upsert/품절판정을 보장한다.
 *
 * 선행 조건: 중복 (샵, external_id) 오퍼를 미리 정리해야 유니크 추가가 성공한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE otaku_offer DROP INDEX uniq_ok_of_product_shop');
        DB::statement('ALTER TABLE otaku_offer DROP INDEX idx_ok_of_shop_external');
        DB::statement("ALTER TABLE otaku_offer MODIFY ok_offer_external_id VARCHAR(255) NOT NULL COMMENT '샵 내부 상품 ID 또는 URL 해시 (오퍼 동일성/사라짐 매칭 키)'");
        DB::statement('ALTER TABLE otaku_offer ADD UNIQUE uniq_ok_of_shop_external (ok_offer_shop_id, ok_offer_external_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE otaku_offer DROP INDEX uniq_ok_of_shop_external');
        DB::statement("ALTER TABLE otaku_offer MODIFY ok_offer_external_id VARCHAR(255) NULL COMMENT '샵 내부 상품 ID 또는 URL 해시 (증분 크롤 시 매칭용)'");
        DB::statement('ALTER TABLE otaku_offer ADD INDEX idx_ok_of_shop_external (ok_offer_shop_id, ok_offer_external_id)');
        DB::statement('ALTER TABLE otaku_offer ADD UNIQUE uniq_ok_of_product_shop (ok_offer_product_id, ok_offer_shop_id)');
    }
};
