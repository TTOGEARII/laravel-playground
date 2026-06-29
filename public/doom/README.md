# DOOM (WebAssembly) 에셋

미니게임 `/mini-game/doom` 에서 사용하는 사전 빌드 에셋.

| 파일 | 내용 |
|------|------|
| `doom1.js` | emscripten 글루 코드 (음악 MP3 패키지 항목 제거하도록 매니페스트 수정됨) |
| `doom1.wasm` | prboom 엔진 WebAssembly 바이너리 |
| `doom1.data` | emscripten 패키지: `prboom.wad` + 셰어웨어 `doom1.wad` (음악 MP3 88MB는 제거) |

## 출처 / 라이선스

- **엔진(prboom)**: GPLv2 — https://github.com/UstymUkhman/webDOOM (prboom 기반 emscripten 포팅)
- **게임 데이터(doom1.wad)**: id Software **셰어웨어** DOOM 에피소드 1. 셰어웨어 라이선스에 따라 재배포 허용.
- 원본 webDOOM 패키지에 포함됐던 고음질 MP3 음악 트랙(약 88MB)은 저작권/용량 문제로 제거했습니다.
  게임 내 음악/효과음은 `doom1.wad` 내장 데이터를 사용합니다.

## 갱신 방법

webDOOM `public/doom1.{js,wasm,data}` 를 받아 음악 항목을 제거하려면:
1. `doom1.data` 를 `doom1.wad` 끝 오프셋(4477040바이트)까지 truncate.
2. `doom1.js` 의 `loadPackage({"files":[...]})` 배열을 `prboom.wad`·`doom1.wad` 두 항목만 남기고,
   `"remote_package_size"` 를 `4477040` 으로 수정.
