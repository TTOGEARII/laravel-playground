/**
 * OtakuShop API (routes/api.php 기준)
 * BASE = /api/otaku-shop
 * GET /api/otaku-shop/products   ?page, per_page, keyword, category_id, ip_id, shop_id[], has_release, sort
 * GET /api/otaku-shop/categories
 * GET /api/otaku-shop/ips
 * GET /api/otaku-shop/shops
 */
import axios from 'axios';

const BASE = '/api/otaku-shop';

export const otakuShopApi = {
  /**
   * 상품 목록 (페이지네이션, 필터)
   * @param {{ page?: number, per_page?: number, keyword?: string, category_id?: number, ip_id?: number, shop_id?: number[], has_release?: boolean, sort?: string }} params
   */
  async getProducts(params = {}) {
    const q = new URLSearchParams();
    if (params.page != null) q.set('page', params.page);
    if (params.per_page != null) q.set('per_page', params.per_page);
    if (params.keyword) q.set('keyword', params.keyword);
    if (params.category_id != null) q.set('category_id', params.category_id);
    if (params.ip_id != null) q.set('ip_id', params.ip_id);
    if (params.has_release) q.set('has_release', '1');
    if (params.upcoming) q.set('upcoming', '1');
    if (params.sort) q.set('sort', params.sort);
    if (params.compared_only) q.set('compared_only', '1');
    if (params.in_stock_only) q.set('in_stock_only', '1');
    (params.shop_id || []).forEach((id) => q.append('shop_id[]', id));
    const { data } = await axios.get(`${BASE}/products?${q}`);
    return data;
  },

  /** 필터용 카테고리 목록 */
  async getCategories() {
    const { data } = await axios.get(`${BASE}/categories`);
    return data;
  },

  /** 필터용 IP(작품) 목록 (상품 많은 순) */
  async getIps() {
    const { data } = await axios.get(`${BASE}/ips`);
    return data;
  },

  /** 필터용 샵 목록 */
  async getShops() {
    const { data } = await axios.get(`${BASE}/shops`);
    return data;
  },
};
