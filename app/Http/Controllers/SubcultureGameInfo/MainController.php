<?php

namespace App\Http\Controllers\SubcultureGameInfo;

use App\Http\Controllers\Controller;
use App\Models\SubcultureGameInfo\CodeRedemption;
use App\Services\SubcultureGameInfo\RedeemCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MainController extends Controller
{
    public function __construct(private RedeemCodeService $codeService) {}

    public function index(Request $request): View
    {
        $games = $this->codeService->gamesForFilter();

        // 게임 필터는 다중 선택 지원(?game=a 또는 ?game[]=a&game[]=b). 유효한 slug만 남긴다.
        $valid = $games->pluck('slug')->all();
        $selected = array_values(array_intersect((array) $request->query('game', []), $valid));

        // 로그인 사용자는 교환 완료 기록을 서버에서 미리 내려준다(초기 렌더 시 깜빡임 방지).
        // 비로그인은 클라이언트 localStorage 로 처리.
        $redeemedIds = Auth::check()
            ? CodeRedemption::where('user_id', Auth::id())->pluck('redeem_code_id')->all()
            : [];

        // 전체 게임 기준으로 한 번 조회 — 화면 표시는 선택 게임만 걸러내되,
        // '안 쓴 코드' 배지는 선택 안 된 탭에도 떠야 하므로 게임별 검증 코드 ID 를 통째로 내려준다.
        $allGroups = $this->codeService->grouped();
        $groups = empty($selected)
            ? $allGroups
            : array_values(array_filter($allGroups, fn (array $g) => in_array($g['game']->slug, $selected, true)));

        return view('subculture-game-info.index', [
            'games' => $games,
            'selected' => $selected,
            'groups' => $groups,
            'verifiedIdsByGame' => collect($allGroups)
                ->mapWithKeys(fn (array $g) => [$g['game']->slug => $g['verified']->pluck('id')->all()])
                ->all(),
            'isLoggedIn' => Auth::check(),
            'redeemedIds' => $redeemedIds,
        ]);
    }
}
