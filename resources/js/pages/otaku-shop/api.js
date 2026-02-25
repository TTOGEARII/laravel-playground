/**
 * OtakuShop API (routes/api.php 기준)
 * BASE = /api/otaku-shop
 * GET /api/otaku-shop/products   ?page, per_page, keyword, category_id, shop_id[], sort
 * GET /api/otaku-shop/categories
 * GET /api/otaku-shop/shops
 */
import axios from 'axios';

const BASE = '/api/otaku-shop';

export const otakuShopApi = {
  /**
   * 상품 목록 (페이지네이션, 필터)
   * @param {{ page?: number, per_page?: number, keyword?: string, category_id?: number, shop_id?: number[], sort?: string }} params
   */
  async getProducts(params = {}) {
    const q = new URLSearchParams();
    if (params.page != null) q.set('page', params.page);
    if (params.per_page != null) q.set('per_page', params.per_page);
    if (params.keyword) q.set('keyword', params.keyword);
    if (params.category_id != null) q.set('category_id', params.category_id);
    if (params.sort) q.set('sort', params.sort);
    (params.shop_id || []).forEach((id) => q.append('shop_id[]', id));
    const { data } = await axios.get(`${BASE}/products?${q}`);
    return data;
  },

  /** 필터용 카테고리 목록 */
  async getCategories() {
    const { data } = await axios.get(`${BASE}/categories`);
    return data;
  },

  /** 필터용 샵 목록 */
  async getShops() {
    const { data } = await axios.get(`${BASE}/shops`);
    return data;
  },
};
