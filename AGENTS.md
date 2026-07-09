# XiunoNext 项目 AI 行为规则

本项目中 AI 仅负责 Git 操作（提交、推送）与返回日志，不主动参与代码开发。

## Git 提交规则

每次 `git commit` 完成后，返回以下两项内容：
1. **更新日志**：直接返回本次 commit message 原始内容，不做额外概括
2. **修改文件列表**：列出本次提交涉及的文件路径（目录/文件名格式）

## DEBUG 值处理

提交前若 `index.php` 中 DEBUG 值不为 0，自动改为 0 再提交，无需询问用户。pre-commit hook 会拦截 DEBUG!=0 的提交。

## 推送失败处理

`git push` 遇到网络或 SSL 错误时，自动使用 Clash 代理重试：

```bash
git -c http.proxy=http://127.0.0.1:7897 -c https.proxy=http://127.0.0.1:7897 push origin main
```

## 通用规则

- 所有回答使用中文。
- 不主动开发代码，仅负责提交推送与返回日志。
