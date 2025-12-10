#!/bin/zsh

echo "=== Git Flow 완전 재설치 (수정 없음) ==="

# 1. 완전 제거
echo "1. git-flow 완전 제거 중..."
brew uninstall --force --ignore-dependencies git-flow-avh 2>/dev/null
sudo rm -rf /opt/homebrew/Cellar/git-flow-avh 2>/dev/null
sudo rm -rf /opt/homebrew/opt/git-flow-avh 2>/dev/null
sudo rm -rf /opt/homebrew/var/homebrew/linked/git-flow-avh 2>/dev/null

# 캐시 삭제
brew cleanup -s 2>/dev/null
rm -rf ~/Library/Caches/Homebrew/git-flow* 2>/dev/null

echo "✅ 제거 완료"
echo ""

# 2. Homebrew 업데이트
echo "2. Homebrew 업데이트 중..."
brew update

# 3. gnu-getopt 확인
echo "3. gnu-getopt 확인 중..."
brew install gnu-getopt
brew link --overwrite --force gnu-getopt

# 4. 환경 변수 설정
export PATH="/opt/homebrew/opt/gnu-getopt/bin:$PATH"
export FLAGS_GETOPT_CMD="/opt/homebrew/opt/gnu-getopt/bin/getopt"

echo "✅ getopt 준비 완료"
echo "  which getopt: $(which getopt)"
echo "  FLAGS_GETOPT_CMD: $FLAGS_GETOPT_CMD"
echo ""

# 5. git-flow 재설치
echo "4. git-flow 재설치 중..."
brew install git-flow-avh

echo "✅ git-flow 설치 완료"
echo ""

# 6. ~/.zshenv 생성
echo "5. ~/.zshenv 생성 중..."
cat > ~/.zshenv << 'ZSHENV'
# GNU getopt 설정
export PATH="/opt/homebrew/opt/gnu-getopt/bin:$PATH"
export FLAGS_GETOPT_CMD="/opt/homebrew/opt/gnu-getopt/bin/getopt"
ZSHENV

echo "✅ ~/.zshenv 생성 완료"
echo ""

# 7. 테스트
echo "6. 테스트 중..."
cd /tmp
rm -rf test-clean-install 2>/dev/null
mkdir test-clean-install
cd test-clean-install
git init > /dev/null 2>&1

echo "   git flow init -d 실행..."
echo ""

if git flow init -d 2>&1; then
    echo ""
    echo "✅✅✅ 성공! ✅✅✅"
    echo ""
    git branch
else
    echo ""
    echo "❌ 여전히 실패"
    echo ""
    echo "에러 로그:"
    git flow init -d
fi

cd - > /dev/null

echo ""
echo "=== 완료 ==="
echo ""
echo "⚠️  터미널을 완전히 재시작하세요 (Command + Q)"
echo ""
echo "재시작 후:"
echo "  cd your-project"
echo "  git flow init -d"
