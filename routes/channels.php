<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * 테트리스 대전 방 — presence 채널. 로그인 사용자·게스트(EnsureTetrisIdentity 가 부여) 모두 입장 허용하고,
 * 멤버 정보(참가자 목록·관전자 판별용)를 반환한다. 참가자/관전자 역할은 입장 순서로 프론트에서 정한다.
 * $user 는 User 또는 GuestParticipant — 공통 Authenticatable 인터페이스로 신원을 얻는다.
 */
Broadcast::channel('tetris-room.{code}', function ($user, string $code) {
    return ['id' => (int) $user->getAuthIdentifier(), 'name' => $user->name ?? '게스트'];
});
