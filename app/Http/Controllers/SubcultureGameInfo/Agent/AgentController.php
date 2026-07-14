<?php

namespace App\Http\Controllers\SubcultureGameInfo\Agent;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\Agent\AgentSession;
use App\Services\SubcultureGameInfo\Agent\PersonaResolver;
use App\Services\SubcultureGameInfo\Agent\SubcultureAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 서브컬쳐 게임 AI 에이전트 — 페이지 + 대화 API(SSE 스트리밍).
 * 세션은 UUID 로만 조회한다(순차 id 추측 방지). 로그인 세션은 소유자만 접근.
 */
class AgentController extends Controller
{
    public function __construct(
        private SubcultureAgentService $agent,
        private PersonaResolver $personas,
    ) {}

    public function index(): View
    {
        return view('subculture-agent.index', [
            'enabled' => $this->agent->enabled(),
            // 게임 컨텍스트 칩 라벨용(슬러그 → 표시명). SGI 화면에서 ?game= 으로 넘어온다.
            'games' => collect(config('subculture-game-info.games', []))
                ->map(fn (array $g) => $g['name'])->all(),
        ]);
    }

    /** 페르소나 선택지 — 프리셋 + 내 챗봇 캐릭터. */
    public function personas(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->personas->options($request->user()?->id)]);
    }

    /** 세션 대화 기록(새로고침 복원용). */
    public function messages(Request $request, AgentSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);

        return response()->json(['data' => [
            'session' => ['uuid' => $session->uuid, 'persona_kind' => $session->persona_kind, 'persona_ref' => $session->persona_ref],
            'messages' => $session->messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'cards' => $m->cards ?? [],
                'tool_calls' => $m->tool_calls ?? [],
            ]),
        ]]);
    }

    /**
     * 대화 — SSE 스트리밍. 이벤트: meta(세션) → tool(진행) → delta(텍스트) → done(카드).
     */
    public function chat(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'session_uuid' => ['nullable', 'uuid'],
            'persona_kind' => ['nullable', 'in:preset,character'],
            'persona_ref' => ['nullable', 'string', 'max:100'],
            // SGI 화면에서 넘어온 게임 컨텍스트 — 게임 미명시 질문의 기준 게임
            'game' => ['nullable', 'string', Rule::in(array_keys(config('subculture-game-info.games', [])))],
        ]);

        $session = $this->resolveSession($request, $validated);

        return response()->stream(function () use ($session, $validated) {
            $emit = function (string $event, array $data) {
                echo "event: {$event}\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $emit('meta', ['session_uuid' => $session->uuid]);

            foreach ($this->agent->stream($session, $validated['message'], $validated['game'] ?? null) as $event) {
                $emit($event['event'], $event['data']);
            }
        }, headers: [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // 프록시 버퍼링 방지(SSE)
        ]);
    }

    /** 기존 세션(UUID) 재사용 또는 새 세션 생성. */
    private function resolveSession(Request $request, array $validated): AgentSession
    {
        if (! empty($validated['session_uuid'])) {
            $session = AgentSession::where('uuid', $validated['session_uuid'])->first();
            if ($session !== null) {
                $this->authorizeSession($request, $session);
                // 게스트로 시작한 세션을 로그인 후 재사용하면 소유자를 채워, 내 캐릭터 풀(get_my_characters)을 쓸 수 있게 한다
                if ($session->user_id === null && $request->user() !== null) {
                    $session->update(['user_id' => $request->user()->id]);
                }

                return $session;
            }
        }

        return AgentSession::create([
            'user_id' => $request->user()?->id,
            'persona_kind' => $validated['persona_kind'] ?? 'preset',
            'persona_ref' => $validated['persona_ref'] ?? 'guide',
        ]);
    }

    /** 로그인 사용자의 세션은 소유자만 접근(게스트 세션은 UUID 소지 = 접근권). */
    private function authorizeSession(Request $request, AgentSession $session): void
    {
        abort_if(
            $session->user_id !== null && $session->user_id !== $request->user()?->id,
            403,
            '이 대화에 접근할 수 없습니다.',
        );
    }
}
