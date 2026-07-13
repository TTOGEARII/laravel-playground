<?php

namespace App\Services\SubcultureGameInfo\Sources;

use App\Services\SubcultureGameInfo\Sources\Concerns\FetchesWebContent;
use Illuminate\Support\Facades\Log;

/**
 * 유튜브 검색 — API 키 없이 검색 페이지의 ytInitialData JSON 을 파싱한다.
 * (이벤트 챌린지 수집기와 AI 에이전트 영상 검색 툴이 공용으로 사용)
 * 실패는 빈 배열 폴백.
 */
class YoutubeSearchClient
{
    use FetchesWebContent;

    /**
     * @return array<int, array{url: string, title: string, video_id: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $html = $this->getHtml('https://www.youtube.com/results', ['search_query' => $query]);
        if ($html === null || ! preg_match('/var ytInitialData = (\{.+?\});<\/script>/s', $html, $m)) {
            Log::info('[YoutubeSearch] 검색 결과 파싱 실패', ['query' => $query]);

            return [];
        }

        $data = json_decode($m[1], true);
        if (! is_array($data)) {
            return [];
        }

        $videos = [];
        $this->collectVideoRenderers($data, $videos);

        return array_slice($videos, 0, max(1, $limit));
    }

    /** ytInitialData 트리에서 videoRenderer(영상 ID·제목)를 재귀 수집한다. */
    private function collectVideoRenderers(array $node, array &$videos): void
    {
        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }
            if ($key === 'videoRenderer' && isset($value['videoId'])) {
                $title = implode('', array_column($value['title']['runs'] ?? [], 'text'));
                if ($title !== '') {
                    $videos[] = [
                        'url' => 'https://www.youtube.com/watch?v='.$value['videoId'],
                        'title' => $title,
                        'video_id' => (string) $value['videoId'],
                    ];
                }

                continue;
            }
            $this->collectVideoRenderers($value, $videos);
        }
    }
}
