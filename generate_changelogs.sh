#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$SCRIPT_DIR"
CHANGELOG_DIR="$REPO_DIR/changelogs"

cd "$REPO_DIR"

if ! git rev-parse --git-dir >/dev/null 2>&1; then
    echo "错误: 当前目录不是 Git 仓库"
    exit 1
fi

mkdir -p "$CHANGELOG_DIR"

# ── 版本定义 ──
declare -a VERSIONS=(
    "v1.0.0|7631712|2089c2c|初始源码导入"
    "v1.0.1|2089c2c|8040f07|核心框架搭建"
    "v1.0.2|8040f07|13828f4|功能完善与安全加固"
    "v1.0.3|13828f4|0bb49c3|引入 version.php"
    "v1.0.4|0bb49c3|8ba4b1d|"
    "v1.0.5|8ba4b1d|0419257|"
    "v1.0.6|0419257|66e33f1|"
    "v1.0.7|66e33f1|5d7668a|"
    "v1.0.8|5d7668a|f4201b8|"
    "v1.0.9|f4201b8|e7a6d3e|"
    "v1.1.0|e7a6d3e|a6bca3e|"
    "v1.1.1|a6bca3e|a961df1|"
    "v1.1.2|a961df1|551e27a|"
    "v1.1.3|551e27a|8100e15|3次version.php提交"
    "v1.1.4|8100e15|6d14d03|"
    "v1.1.5|6d14d03|21c64d6|"
)

# ── 过滤函数 ──
should_skip_commit() {
    local subject="$1"

    # Merge commits
    if echo "$subject" | grep -qi '^Merge '; then
        return 0
    fi

    local lower
    lower="$(echo "$subject" | tr '[:upper:]' '[:lower:]')"

    # Conventional commit type prefix: chore/docs/ci/test/style
    if echo "$lower" | grep -qE '^(chore|docs|ci|test|style)[[:space:]]*:'; then
        return 0
    fi

    # 中文标签过滤（chore/ci/docs/test/style 在中文提交中常作为前缀）
    if echo "$subject" | grep -qE '^(chore|ci|docs|test|style)[[:space:]]'; then
        return 0
    fi

    # 版本号更新类提交（仅匹配独立版本号提交）
    if echo "$lower" | grep -qE '^(bump|update)[[:space:]]+version([[:space:]]|$)'; then
        return 0
    fi
    if echo "$lower" | grep -qE '^版本号更新'; then
        return 0
    fi

    # 纯文档更新（仅修改 .md/README 等文档文件的提交）
    if echo "$lower" | grep -qE '^(update|add)[[:space:]]+(readme|install|changelog|.*\.md)'; then
        local has_code=0
        for keyword in 'admin' 'route' 'api' 'model' 'view' 'config' 'service' 'lib/' 'class/' '.php'; do
            if echo "$lower" | grep -q "$keyword"; then
                has_code=1
                break
            fi
        done
        if [ "$has_code" -eq 0 ]; then
            return 0
        fi
    fi

    return 1
}

# ── 分类函数 ──
# 结果写入全局变量 _CATEGORY: 0=Added 1=Fixed 2=Changed 3=Deprecated 4=Removed 5=Security
classify_commit() {
    local subject="$1"
    local lower
    lower="$(echo "$subject" | tr '[:upper:]' '[:lower:]')"

    # style 已在过滤中处理，这里仅保留作为安全网
    if echo "$lower" | grep -qE '^style[[:space:]]*:' || echo "$lower" | grep -qE '^style[[:space:]]'; then
        _CATEGORY=2
        return
    fi

    # Added: feat/feature/add 前缀
    if echo "$lower" | grep -qE '^(feat|feature|add)[[:space:]]*:' || \
       echo "$lower" | grep -qE '^(feat|feature|add)[[:space:]]'; then
        _CATEGORY=0
        return
    fi

    # Added: 中文关键词
    if echo "$lower" | grep -q '新增'; then
        _CATEGORY=0
        return
    fi

    # Fixed: fix/bug/hotfix 前缀（词边界）
    if echo "$lower" | grep -qE '^(fix|bug|hotfix)[[:space:]]*:' || \
       echo "$lower" | grep -qE '^(fix|bug|hotfix)[[:space:]]'; then
        _CATEGORY=1
        return
    fi

    # Fixed: 中文关键词
    if echo "$lower" | grep -q '修复'; then
        _CATEGORY=1
        return
    fi

    # Security: security/安全
    if echo "$lower" | grep -qE '(security|安全)'; then
        _CATEGORY=5
        return
    fi

    # Changed: perf/refactor/optimize 前缀
    if echo "$lower" | grep -qE '^(perf|refactor|optimize)[[:space:]]*:' || \
       echo "$lower" | grep -qE '^(perf|refactor|optimize)[[:space:]]'; then
        _CATEGORY=2
        return
    fi

    # Changed: 中文关键词
    if echo "$lower" | grep -qE '(优化|重构)'; then
        _CATEGORY=2
        return
    fi

    # Deprecated
    if echo "$lower" | grep -qE '(deprecate|废弃)'; then
        _CATEGORY=3
        return
    fi

    # Removed: remove/删除/丢弃
    if echo "$lower" | grep -qE '(remove|删除|丢弃)'; then
        _CATEGORY=4
        return
    fi

    # Default: Changed
    _CATEGORY=2
}

# ── 清理提交描述 ──
clean_subject() {
    local subject="$1"

    # 去除 conventional commit 前缀
    subject="$(echo "$subject" | sed -E 's/^(feat|feature|fix|bug|hotfix|perf|refactor|deprecate|remove|security|add|optimize|style|重构|修复|新增|优化|安全|删除|废弃)[[:space:]]*:[[:space:]]*//i')"

    # 去除 "vX.Y.Z — " 版本号前缀
    subject="$(echo "$subject" | sed -E 's/^v[0-9]+\.[0-9]+\.[0-9]+[[:space:]]*—[[:space:]]*//')"

    # 去除 "Major update: " / "Update: " 等前缀
    subject="$(echo "$subject" | sed -E 's/^(major[[:space:]]*update|major|update|refactor|add)[[:space:]]*:[[:space:]]*//i')"

    # 去除首尾空白
    subject="$(echo "$subject" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

    if [ -z "$subject" ]; then
        echo "$1"
    else
        echo "$subject"
    fi
}

# ── Breaking Change 检测 ──
has_breaking_change() {
    local subject="$1"
    local lower
    lower="$(echo "$subject" | tr '[:upper:]' '[:lower:]')"

    if echo "$lower" | grep -qE '(breaking[[:space:]]change|破坏性|remove|drop|force|replace|迁移|强制)'; then
        return 0
    fi

    return 1
}

# ── 自动生成版本说明（基于版本范围内首个 commit） ──
generate_auto_description() {
    local start_commit="$1"
    local end_commit="$2"

    local first_subject
    first_subject="$(git log --reverse --format="%s" "$start_commit..$end_commit" 2>/dev/null | head -1)"

    if [ -z "$first_subject" ]; then
        echo ""
        return
    fi

    local cleaned
    cleaned="$(clean_subject "$first_subject")"

    if [ -z "$cleaned" ]; then
        cleaned="$first_subject"
    fi

    echo "本版本主要内容：${cleaned}"
}

# ── 生成单个版本的 Changelog ──
generate_version_changelog() {
    local version="$1"
    local start_commit="$2"
    local end_commit="$3"
    local description="$4"

    if [ -z "$description" ]; then
        description="$(generate_auto_description "$start_commit" "$end_commit")"
    fi

    local output_file="$CHANGELOG_DIR/CHANGELOG_${version}.md"

    local release_date
    release_date="$(git log --format="%ad" --date=short -1 "$end_commit" 2>/dev/null || echo "未知")"
    if [ -z "$release_date" ]; then
        release_date="未知"
    fi

    local commits_raw
    commits_raw="$(git log --format="%H|%s" "$start_commit..$end_commit" 2>/dev/null || true)"

    if [ -z "$commits_raw" ]; then
        cat > "$output_file" << EOF
# [${version#v}] - ${release_date}

> 无提交记录
EOF
        echo "  → $output_file (空版本)"
        return
    fi

    local -a added_list=()
    local -a fixed_list=()
    local -a changed_list=()
    local -a deprecated_list=()
    local -a removed_list=()
    local -a security_list=()
    local has_breaking=0
    local breaking_desc=""

    local old_shell_state
    old_shell_state="$(set +o)"
    set +e

    while IFS='|' read -r hash subject; do
        [ -z "$subject" ] && continue

        if should_skip_commit "$subject"; then
            continue
        fi

        if has_breaking_change "$subject"; then
            has_breaking=1
            local cleaned
            cleaned="$(clean_subject "$subject")"
            if [ -n "$breaking_desc" ]; then
                breaking_desc+=", ${cleaned}"
            else
                breaking_desc="${cleaned}"
            fi
        fi

        classify_commit "$subject"
        local cat="$_CATEGORY"

        local cleaned_subject
        cleaned_subject="$(clean_subject "$subject")"

        case $cat in
            0) added_list+=("$cleaned_subject") ;;
            1) fixed_list+=("$cleaned_subject") ;;
            2) changed_list+=("$cleaned_subject") ;;
            3) deprecated_list+=("$cleaned_subject") ;;
            4) removed_list+=("$cleaned_subject") ;;
            5) security_list+=("$cleaned_subject") ;;
        esac
    done <<< "$commits_raw"

    eval "$old_shell_state"

    local total_valid=$(( ${#added_list[@]} + ${#fixed_list[@]} + ${#changed_list[@]} + ${#deprecated_list[@]} + ${#removed_list[@]} + ${#security_list[@]} ))

    {
        echo "# [${version#v}] - ${release_date}"
        echo ""

        if [ -n "$description" ]; then
            echo "> **版本说明**: ${description}"
            echo ""
        fi

        if [ "$has_breaking" -eq 1 ]; then
            echo "> **⚠️ Breaking Changes**: ${breaking_desc}"
            echo ""
        fi

        if [ ${#added_list[@]} -gt 0 ]; then
            echo "## Added"
            for item in "${added_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ ${#fixed_list[@]} -gt 0 ]; then
            echo "## Fixed"
            for item in "${fixed_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ ${#changed_list[@]} -gt 0 ]; then
            echo "## Changed"
            for item in "${changed_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ ${#deprecated_list[@]} -gt 0 ]; then
            echo "## Deprecated"
            for item in "${deprecated_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ ${#removed_list[@]} -gt 0 ]; then
            echo "## Removed"
            for item in "${removed_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ ${#security_list[@]} -gt 0 ]; then
            echo "## Security"
            for item in "${security_list[@]}"; do
                echo "- ${item}"
            done
            echo ""
        fi

        if [ "$total_valid" -eq 0 ]; then
            echo "> 无用户可感知的变更"
            echo ""
        fi

    } > "$output_file"

    echo "  → $output_file (${total_valid} 条有效提交)"
}

# ── 主流程 ──
echo "========================================="
echo "  XiunoX Changelog 生成器"
echo "========================================="
echo ""
echo "输出目录: $CHANGELOG_DIR"
echo ""

for version_entry in "${VERSIONS[@]}"; do
    IFS='|' read -r version start_commit end_commit description <<< "$version_entry"
    echo "处理 $version ($start_commit..$end_commit)..."
    generate_version_changelog "$version" "$start_commit" "$end_commit" "$description"
done

echo ""
echo "========================================="
echo " 完成！共生成 ${#VERSIONS[@]} 个 Changelog 文件"
echo " 输出目录: $CHANGELOG_DIR"
echo "========================================="