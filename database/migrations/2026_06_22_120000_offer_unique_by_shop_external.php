<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 오퍼 동일성 기준을 (상품, 샵) → (샵, external_id)로 전환한다.
 *
 * 정규화 키(상품)는 매칭 사전 변경에 따라 달라져, 키를 식별자로 쓰면 같은 listing이 새 오퍼로
 * 중복 생성되고 옛 오퍼가 '사라짐=품절'로 오인됐다. 샵 내부 상품ID(external_id)는 회차 간
 * 불변이므로 이를 유니크 키로 삼아 안정적인 upsert/품절판정을 보장한다.
 *
 * MySQL은 원시 DDL(인덱스 존재 확인으로 멱등)로, sqlite(test)는 스키마 빌더로 동일 결과를 만든다.
 * 유니크 추가 전 중복 (샵, external_id) 오퍼를 가장 최근(id 큰) 1건만 남기고 정리한다.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->dedupeShopExternal();
            Schema::table('otaku_offer', function (Blueprint $table) {
                $table->dropUnique('uniq_ok_of_product_shop');
            });
            Schema::table('otaku_offer', function (Blueprint $table) {
                $table->unique(['ok_offer_shop_id', 'ok_offer_external_id'], 'uniq_ok_of_shop_external');
            });

            return;
        }

        foreach (['uniq_ok_of_product_shop', 'idx_ok_of_shop_external'] as $index) {
            if ($this->indexExists('otaku_offer', $index)) {
                DB::statement("ALTER TABLE otaku_offer DROP INDEX {$index}");
            }
        }
        DB::statement("ALTER TABLE otaku_offer MODIFY ok_offer_external_id VARCHAR(255) NOT NULL COMMENT '샵 내부 상품 ID 또는 URL 해시 (오퍼 동일성/사라짐 매칭 키)'");
        if (! $this->indexExists('otaku_offer', 'uniq_ok_of_shop_external')) {
            // 보조 인덱스로 그룹핑/삭제를 인덱스 기반(빠르게)으로. (인덱스 없는 자기조인은 O(n^2)라 멈춘다.)
            if (! $this->indexExists('otaku_offer', 'tmp_shop_ext')) {
                DB::statement('ALTER TABLE otaku_offer ADD INDEX tmp_shop_ext (ok_offer_shop_id, ok_offer_external_id)');
            }
            $this->dedupeShopExternal();
            DB::statement('ALTER TABLE otaku_offer ADD UNIQUE uniq_ok_of_shop_external (ok_offer_shop_id, ok_offer_external_id)');
            DB::statement('ALTER TABLE otaku_offer DROP INDEX tmp_shop_ext');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('otaku_offer', function (Blueprint $table) {
                $table->dropUnique('uniq_ok_of_shop_external');
            });
            Schema::table('otaku_offer', function (Blueprint $table) {
                $table->unique(['ok_offer_product_id', 'ok_offer_shop_id'], 'uniq_ok_of_product_shop');
            });

            return;
        }

        if ($this->indexExists('otaku_offer', 'uniq_ok_of_shop_external')) {
            DB::statement('ALTER TABLE otaku_offer DROP INDEX uniq_ok_of_shop_external');
        }
        DB::statement("ALTER TABLE otaku_offer MODIFY ok_offer_external_id VARCHAR(255) NULL COMMENT '샵 내부 상품 ID 또는 URL 해시 (증분 크롤 시 매칭용)'");
        if (! $this->indexExists('otaku_offer', 'idx_ok_of_shop_external')) {
            DB::statement('ALTER TABLE otaku_offer ADD INDEX idx_ok_of_shop_external (ok_offer_shop_id, ok_offer_external_id)');
        }
        if (! $this->indexExists('otaku_offer', 'uniq_ok_of_product_shop')) {
            DB::statement('ALTER TABLE otaku_offer ADD UNIQUE uniq_ok_of_product_shop (ok_offer_product_id, ok_offer_shop_id)');
        }
    }

    /** 같은 (샵, external_id) 중복 오퍼를 가장 최근(id 큰) 1건만 남기고 정리. */
    private function dedupeShopExternal(): void
    {
        DB::statement('DELETE FROM otaku_offer WHERE ok_offer_id NOT IN (
            SELECT keep_id FROM (
                SELECT MAX(ok_offer_id) AS keep_id
                FROM otaku_offer
                GROUP BY ok_offer_shop_id, ok_offer_external_id
            ) t
        )');
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
