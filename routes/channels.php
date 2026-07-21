<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
 * 테트리스 대전 방 — presence 채널. 로그인 사용자면 방에 입장 허용하고,
 * 멤버 정보(참가자 목록·관전자 판별용)를 반환한다. 참가자/관전자 역할은 입장 순서로 프론트에서 정한다.
 */
Broadcast::channel('tetris-room.{code}', function ($user, string $code) {
    return ['id' => $user->id, 'name' => $user->name];
});
