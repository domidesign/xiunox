<?php

class ApiDocService {

    public static function getEndpoints(): array {
        return [
            'auth' => [
                'name' => '认证',
                'icon' => 'ti ti-lock',
                'endpoints' => [
                    [
                        'method' => 'POST',
                        'path' => '/auth/login',
                        'summary' => '登录获取令牌',
                        'auth' => false,
                        'level' => 'Public',
                        'params' => [
                            ['name' => 'email', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '邮箱或用户名'],
                            ['name' => 'password', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '密码'],
                        ],
                        'request_example' => ['email' => 'admin@xiuno.com', 'password' => '123456'],
                        'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['access_token' => 'abc...', 'refresh_token' => 'def...', 'expires_in' => 7200, 'user' => ['uid' => 1, 'username' => 'admin']]],
                        'errors' => [401 => '用户名或密码错误', 422 => '参数验证失败'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/register',
                        'summary' => '注册新用户',
                        'auth' => false,
                        'level' => 'Public',
                        'params' => [
                            ['name' => 'email', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '邮箱'],
                            ['name' => 'username', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '用户名'],
                            ['name' => 'password', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '密码'],
                        ],
                        'request_example' => ['email' => 'test@example.com', 'username' => 'testuser', 'password' => '123456'],
                        'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['uid' => 2, 'access_token' => 'abc...', 'refresh_token' => 'def...', 'expires_in' => 7200]],
                        'errors' => [409 => '邮箱或用户名已存在', 422 => '参数验证失败'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/refresh',
                        'summary' => '刷新令牌',
                        'auth' => false,
                        'level' => 'Public',
                        'params' => [
                            ['name' => 'refresh_token', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '刷新令牌'],
                        ],
                        'request_example' => ['refresh_token' => 'def...'],
                        'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['access_token' => 'new_abc...', 'refresh_token' => 'new_def...', 'expires_in' => 7200]],
                        'errors' => [401 => '无效或过期的刷新令牌'],
                    ],
                    [
                        'method' => 'POST',
                        'path' => '/auth/logout',
                        'summary' => '退出登录',
                        'auth' => true,
                        'level' => 'Authenticated',
                        'params' => [
                            ['name' => 'refresh_token', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '刷新令牌'],
                        ],
                        'request_example' => ['refresh_token' => 'def...'],
                        'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null],
                        'errors' => [401 => '未授权'],
                    ],
                ],
            ],
            'user' => [
                'name' => '用户',
                'icon' => 'ti ti-user',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/user', 'summary' => '用户列表', 'auth' => false, 'level' => 'Authenticated', 'params' => [['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 50, 'total_pages' => 3]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/user/me', 'summary' => '当前登录用户', 'auth' => true, 'level' => 'Authenticated', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['uid' => 1, 'username' => 'admin']], 'errors' => [401 => '未授权']],
                    ['method' => 'GET', 'path' => '/user/{uid}', 'summary' => '用户详情', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['uid' => 1, 'username' => 'admin']], 'errors' => [404 => '用户不存在']],
                    ['method' => 'PUT', 'path' => '/user/{uid}', 'summary' => '修改用户', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'username', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '用户名'], ['name' => 'email', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '邮箱'], ['name' => 'password', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '新密码']], 'request_example' => ['username' => 'newname'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['uid' => 1, 'username' => 'newname']], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '用户不存在']],
                    ['method' => 'GET', 'path' => '/user/{uid}/threads', 'summary' => '用户帖子列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 10, 'total_pages' => 1]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/user/{uid}/posts', 'summary' => '用户回复列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 5, 'total_pages' => 1]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/user/{uid}/favorites', 'summary' => '用户收藏列表', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 3, 'total_pages' => 1]]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'GET', 'path' => '/user', 'summary' => '批量获取用户', 'auth' => false, 'level' => 'Authenticated', 'params' => [['name' => 'ids', 'type' => 'string', 'in' => 'query', 'required' => true, 'desc' => '用户ID列表，逗号分隔'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => [['uid' => 1, 'username' => 'admin'], ['uid' => 2, 'username' => 'test']]], 'errors' => [422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/user/{uid}/following', 'summary' => '用户关注列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 5, 'total_pages' => 1]]], 'errors' => [404 => '用户不存在']],
                    ['method' => 'GET', 'path' => '/user/{uid}/followers', 'summary' => '用户粉丝列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 8, 'total_pages' => 1]]], 'errors' => [404 => '用户不存在']],
                    ['method' => 'GET', 'path' => '/user/{uid}/ai-config', 'summary' => '获取用户AI配置', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['ai_provider' => 'openai', 'ai_endpoint' => 'https://api.openai.com', 'ai_model' => 'gpt-4']], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'PUT', 'path' => '/user/{uid}/ai-config', 'summary' => '更新用户AI配置', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'ai_provider', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => 'AI提供商'], ['name' => 'ai_apikey', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => 'API密钥'], ['name' => 'ai_endpoint', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => 'API端点'], ['name' => 'ai_model', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '模型名称']], 'request_example' => ['ai_provider' => 'openai', 'ai_model' => 'gpt-4'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['ai_provider' => 'openai', 'ai_model' => 'gpt-4']], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'POST', 'path' => '/user/{uid}/avatar', 'summary' => '上传头像', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'file', 'type' => 'file', 'in' => 'body', 'required' => true, 'desc' => '头像文件']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['avatar_url' => 'upload/avatar/1.jpg']], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '文件类型不允许']],
                    ['method' => 'POST', 'path' => '/user/{uid}/avatar/preset', 'summary' => '选择预设头像', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID'], ['name' => 'avatar_index', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '预设头像序号']], 'request_example' => ['avatar_index' => 3], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['avatar_url' => 'upload/avatar/preset_3.png']], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/user/{uid}/avatar/presets', 'summary' => '获取预设头像列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '用户ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['presets' => [['index' => 1, 'url' => 'upload/avatar/preset_1.png'], ['index' => 2, 'url' => 'upload/avatar/preset_2.png']]]], 'errors' => [404 => '用户不存在']],
                    ['method' => 'POST', 'path' => '/user/{uid}/follow', 'summary' => '关注用户', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '目标用户ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['following' => true]], 'errors' => [401 => '未授权', 404 => '用户不存在']],
                    ['method' => 'DELETE', 'path' => '/user/{uid}/follow', 'summary' => '取消关注用户', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '目标用户ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['following' => false]], 'errors' => [401 => '未授权']],
                ],
            ],
            'thread' => [
                'name' => '帖子',
                'icon' => 'ti ti-message',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/thread', 'summary' => '帖子列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '版块ID筛选'], ['name' => 'uid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '用户ID筛选'], ['name' => 'keyword', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '关键词搜索'], ['name' => 'orderby', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '排序字段'], ['name' => 'order', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '排序方向 -1降序 1升序'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 100, 'total_pages' => 5]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/thread/{tid}', 'summary' => '帖子详情', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['tid' => 1, 'fid' => 1, 'subject' => '测试帖子']], 'errors' => [404 => '帖子不存在']],
                    ['method' => 'POST', 'path' => '/thread', 'summary' => '创建帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'fid', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '版块ID'], ['name' => 'subject', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '标题'], ['name' => 'message', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '内容']], 'request_example' => ['fid' => 1, 'subject' => '测试', 'message' => '内容'], 'response_example' => ['code' => 0, 'msg' => 'Created', 'data' => ['tid' => 1]], 'errors' => [401 => '未授权', 422 => '参数验证失败']],
                    ['method' => 'PUT', 'path' => '/thread/{tid}', 'summary' => '更新帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID'], ['name' => 'subject', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '新标题']], 'request_example' => ['subject' => '新标题'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['tid' => 1, 'subject' => '新标题']], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '帖子不存在']],
                    ['method' => 'DELETE', 'path' => '/thread/{tid}', 'summary' => '删除帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'Deleted', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '帖子不存在']],
                    ['method' => 'POST', 'path' => '/thread/{tid}/like', 'summary' => '点赞', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['liked' => true]], 'errors' => [401 => '未授权', 404 => '帖子不存在']],
                    ['method' => 'DELETE', 'path' => '/thread/{tid}/like', 'summary' => '取消点赞', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['liked' => false]], 'errors' => [401 => '未授权']],
                    ['method' => 'POST', 'path' => '/thread/{tid}/favorite', 'summary' => '收藏', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['favorited' => true]], 'errors' => [401 => '未授权', 404 => '帖子不存在']],
                    ['method' => 'DELETE', 'path' => '/thread/{tid}/favorite', 'summary' => '取消收藏', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['favorited' => false]], 'errors' => [401 => '未授权']],
                    ['method' => 'POST', 'path' => '/thread/{tid}/report', 'summary' => '举报', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID'], ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '举报原因']], 'request_example' => ['reason' => '违规内容'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 404 => '帖子不存在', 422 => '参数验证失败']],
                    ['method' => 'DELETE', 'path' => '/thread/batch', 'summary' => '批量删除帖子（管理员）', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tids', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组']], 'request_example' => ['tids' => [1, 2, 3]], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => 3]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'PUT', 'path' => '/thread/batch', 'summary' => '批量更新帖子（管理员）', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tids', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组'], ['name' => 'update', 'type' => 'object', 'in' => 'body', 'required' => true, 'desc' => '更新内容（top/closed/type）']], 'request_example' => ['tids' => [1, 2], 'update' => ['top' => 1]], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['updated' => 2]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/thread', 'summary' => '批量获取帖子', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'ids', 'type' => 'string', 'in' => 'query', 'required' => true, 'desc' => '帖子ID列表，逗号分隔'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => [['tid' => 1, 'subject' => '帖子1'], ['tid' => 2, 'subject' => '帖子2']]], 'errors' => [422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/thread/hot', 'summary' => '近期热门帖子', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'days', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '天数范围，默认7'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量，默认10']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => []]], 'errors' => []],
                    ['method' => 'POST', 'path' => '/thread/{tid}/announcement', 'summary' => '设置/取消公告', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '帖子ID'], ['name' => 'is_announcement', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '0=取消,1=设置'], ['name' => 'announcement_order', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '排序权重']], 'request_example' => ['is_announcement' => 1, 'announcement_order' => 0], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['tid' => 1, 'is_announcement' => 1]], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '帖子不存在']],
                ],
            ],
            'post' => [
                'name' => '回复',
                'icon' => 'ti ti-message-reply',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/post', 'summary' => '回复列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '帖子ID'], ['name' => 'uid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '用户ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 50, 'total_pages' => 3]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/post/{pid}', 'summary' => '回复详情', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'pid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '回复ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['pid' => 1, 'tid' => 1, 'message' => '回复']], 'errors' => [404 => '回复不存在']],
                    ['method' => 'POST', 'path' => '/post', 'summary' => '创建回复', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tid', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '帖子ID'], ['name' => 'message', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '回复内容']], 'request_example' => ['tid' => 1, 'message' => '回复'], 'response_example' => ['code' => 0, 'msg' => 'Created', 'data' => ['pid' => 1]], 'errors' => [401 => '未授权', 404 => '帖子不存在', 422 => '参数验证失败']],
                    ['method' => 'PUT', 'path' => '/post/{pid}', 'summary' => '修改回复', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'pid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '回复ID'], ['name' => 'message', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '新内容']], 'request_example' => ['message' => '修改后'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['pid' => 1, 'message' => '修改后']], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '回复不存在']],
                    ['method' => 'DELETE', 'path' => '/post/{pid}', 'summary' => '删除回复', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'pid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '回复ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'Deleted', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '回复不存在']],
                    ['method' => 'DELETE', 'path' => '/post/batch', 'summary' => '批量删除回复（管理员）', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'pids', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '回复ID数组']], 'request_example' => ['pids' => [1, 2, 3]], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => 3]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'POST', 'path' => '/post/{pid}/like', 'summary' => '点赞回复', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'pid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '回复ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['liked' => true]], 'errors' => [401 => '未授权', 404 => '回复不存在']],
                    ['method' => 'DELETE', 'path' => '/post/{pid}/like', 'summary' => '取消点赞回复', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'pid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '回复ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['liked' => false]], 'errors' => [401 => '未授权']],
                ],
            ],
            'forum' => [
                'name' => '版块',
                'icon' => 'ti ti-layout-list',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/forum', 'summary' => '版块列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 5, 'total_pages' => 1]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/forum/{fid}', 'summary' => '版块详情', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'fid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '版块ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['fid' => 1, 'name' => '默认版块']], 'errors' => [404 => '版块不存在']],
                    ['method' => 'GET', 'path' => '/forum/{fid}/threads', 'summary' => '版块帖子列表', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'fid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '版块ID'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'orderby', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '排序字段'], ['name' => 'keyword', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '关键词搜索']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 50, 'total_pages' => 3]]], 'errors' => [404 => '版块不存在']],
                    ['method' => 'GET', 'path' => '/forum/tree', 'summary' => '版块树形结构', 'auth' => false, 'level' => 'Public', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => [['fid' => 1, 'name' => '默认版块', 'children' => []]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/forum', 'summary' => '批量获取版块', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'ids', 'type' => 'string', 'in' => 'query', 'required' => true, 'desc' => '版块ID列表，逗号分隔'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => [['fid' => 1, 'name' => '默认版块'], ['fid' => 2, 'name' => '技术版块']]], 'errors' => [422 => '参数验证失败']],
                    ['method' => 'POST', 'path' => '/forum/follow', 'summary' => '关注版块', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'fid', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '版块ID']], 'request_example' => ['fid' => 1], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['following' => true]], 'errors' => [401 => '未授权', 404 => '版块不存在']],
                    ['method' => 'POST', 'path' => '/forum/unfollow', 'summary' => '取消关注版块', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'fid', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '版块ID']], 'request_example' => ['fid' => 1], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['following' => false]], 'errors' => [401 => '未授权']],
                ],
            ],
            'attach' => [
                'name' => '附件',
                'icon' => 'ti ti-paperclip',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/attach/{aid}', 'summary' => '附件详情', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'aid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '附件ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['aid' => 1, 'filename' => 'image.jpg']], 'errors' => [404 => '附件不存在']],
                    ['method' => 'POST', 'path' => '/attach', 'summary' => '上传附件', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'file', 'type' => 'file', 'in' => 'body', 'required' => true, 'desc' => '附件文件'], ['name' => 'tid', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '帖子ID'], ['name' => 'pid', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '回复ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'Uploaded', 'data' => ['aid' => 1, 'url' => 'upload/attach/202605/image.jpg']], 'errors' => [401 => '未授权', 422 => '文件类型不允许']],
                    ['method' => 'DELETE', 'path' => '/attach/{aid}', 'summary' => '删除附件', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'aid', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '附件ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'Deleted', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '附件不存在']],
                ],
            ],
            'notify' => [
                'name' => '通知',
                'icon' => 'ti ti-bell',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/notify', 'summary' => '通知列表', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '通知类型']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 5, 'total_pages' => 1]]], 'errors' => [401 => '未授权']],
                    ['method' => 'GET', 'path' => '/notify/unread', 'summary' => '未读通知数', 'auth' => true, 'level' => 'Authenticated', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['count' => 3]], 'errors' => [401 => '未授权']],
                    ['method' => 'PUT', 'path' => '/notify/{id}/read', 'summary' => '标记已读', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'id', 'type' => 'int', 'in' => 'path', 'required' => true, 'desc' => '通知ID']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 422 => '参数验证失败']],
                    ['method' => 'PUT', 'path' => '/notify/read-all', 'summary' => '全部标记已读', 'auth' => true, 'level' => 'Authenticated', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权']],
                ],
            ],
            'search' => [
                'name' => '搜索',
                'icon' => 'ti ti-search',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/search', 'summary' => '全文搜索', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'q', 'type' => 'string', 'in' => 'query', 'required' => true, 'desc' => '搜索关键词（至少2字符）'], ['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '搜索类型：thread/post/user'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 10, 'total_pages' => 1]]], 'errors' => [422 => '参数验证失败']],
                ],
            ],
            'site' => [
                'name' => '站点',
                'icon' => 'ti ti-world',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/site', 'summary' => '站点信息', 'auth' => false, 'level' => 'Public', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['name' => 'Xiuno BBS', 'api_version' => '1.0']], 'errors' => []],
                    ['method' => 'GET', 'path' => '/site/stats', 'summary' => '站点统计', 'auth' => false, 'level' => 'Public', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['threads' => 100, 'posts' => 500, 'users' => 50]], 'errors' => []],
                ],
            ],
            'mod' => [
                'name' => '管理操作',
                'icon' => 'ti ti-shield',
                'endpoints' => [
                    ['method' => 'POST', 'path' => '/mod/top', 'summary' => '置顶/取消置顶', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tidarr', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组'], ['name' => 'top', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '置顶级别：0=取消,1=版块置顶,3=全局置顶']], 'request_example' => ['tidarr' => [1, 2], 'top' => 1], 'response_example' => ['code' => 0, 'message' => '设置完成', 'redirect_url' => './'], 'errors' => [401 => '未授权', 403 => '权限不足']],
                    ['method' => 'POST', 'path' => '/mod/close', 'summary' => '关闭/打开帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tidarr', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组'], ['name' => 'close', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '0=打开,1=关闭']], 'request_example' => ['tidarr' => [1], 'close' => 1], 'response_example' => ['code' => 0, 'message' => '设置完成', 'redirect_url' => './'], 'errors' => [401 => '未授权', 403 => '权限不足']],
                    ['method' => 'POST', 'path' => '/mod/delete', 'summary' => '删除帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tidarr', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组']], 'request_example' => ['tidarr' => [1, 2]], 'response_example' => ['code' => 0, 'message' => '删除完成', 'redirect_url' => './'], 'errors' => [401 => '未授权', 403 => '权限不足']],
                    ['method' => 'POST', 'path' => '/mod/move', 'summary' => '移动帖子', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tidarr', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组'], ['name' => 'newfid', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '目标版块ID']], 'request_example' => ['tidarr' => [1], 'newfid' => 2], 'response_example' => ['code' => 0, 'message' => '移动完成', 'redirect_url' => './'], 'errors' => [401 => '未授权', 403 => '权限不足', 404 => '版块不存在']],
                    ['method' => 'POST', 'path' => '/mod/announcement', 'summary' => '设置/取消公告', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'tidarr', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '帖子ID数组'], ['name' => 'is_announcement', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '0=取消,1=设置'], ['name' => 'announcement_order', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '排序权重']], 'request_example' => ['tidarr' => [1], 'is_announcement' => 1, 'announcement_order' => 0], 'response_example' => ['code' => 0, 'message' => '设置完成', 'redirect_url' => './'], 'errors' => [401 => '未授权', 403 => '权限不足']],
                ],
            ],
            'credits' => [
                'name' => '积分',
                'icon' => 'ti ti-coin',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/credits', 'summary' => '查询当前用户积分余额', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '积分类型']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['credits' => 100, 'type' => 'default']], 'errors' => [401 => '未授权']],
                    ['method' => 'GET', 'path' => '/credits/log', 'summary' => '查询积分日志', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '积分类型']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 10, 'total_pages' => 1]]], 'errors' => [401 => '未授权']],
                    ['method' => 'POST', 'path' => '/credits/add', 'summary' => '增加积分', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '用户ID（默认当前用户）'], ['name' => 'type', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '积分类型'], ['name' => 'amount', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '增加数量'], ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '增加原因']], 'request_example' => ['amount' => 10, 'reason' => '签到奖励'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['credits' => 110]], 'errors' => [401 => '未授权', 422 => '参数验证失败']],
                    ['method' => 'POST', 'path' => '/credits/sub', 'summary' => '扣减积分', 'auth' => true, 'level' => 'Authenticated', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '用户ID（默认当前用户）'], ['name' => 'type', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '积分类型'], ['name' => 'amount', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '扣减数量'], ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '扣减原因']], 'request_example' => ['amount' => 5, 'reason' => '下载附件'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['credits' => 105]], 'errors' => [401 => '未授权', 422 => '参数验证失败', 403 => '积分不足']],
                ],
            ],
            'rank' => [
                'name' => '排行榜',
                'icon' => 'ti ti-trophy',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/rank', 'summary' => '排行榜概览', 'auth' => false, 'level' => 'Public', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['hot_threads' => [], 'active_users' => []]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/rank/threads', 'summary' => '热门帖子排行', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'period', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '时间范围：week/month/all'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'page_size', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 50, 'total_pages' => 3]]], 'errors' => []],
                    ['method' => 'GET', 'path' => '/rank/users', 'summary' => '活跃用户排行', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'period', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '时间范围'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'page_size', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量'], ['name' => 'fields', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '返回字段过滤']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 30, 'total_pages' => 2]]], 'errors' => []],
                ],
            ],
            'captcha' => [
                'name' => '验证码',
                'icon' => 'ti ti-shield-check',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/captcha/{scene}', 'summary' => '生成验证码', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'scene', 'type' => 'string', 'in' => 'path', 'required' => true, 'desc' => '场景：login/register/post/reply']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['captcha_key' => 'abc123', 'image' => 'data:image/png;base64,...']], 'errors' => [422 => '参数验证失败']],
                    ['method' => 'POST', 'path' => '/captcha/{scene}/verify', 'summary' => '验证验证码', 'auth' => false, 'level' => 'Public', 'params' => [['name' => 'scene', 'type' => 'string', 'in' => 'path', 'required' => true, 'desc' => '场景：login/register/post/reply'], ['name' => 'captcha', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '验证码内容']], 'request_example' => ['captcha' => 'a3x7'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['valid' => true]], 'errors' => [422 => '参数验证失败', 400 => '验证码错误或已过期']],
                ],
            ],
            'admin' => [
                'name' => '管理',
                'icon' => 'ti ti-settings',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/admin/security', 'summary' => '获取安全配置', 'auth' => true, 'level' => 'Admin', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['security_captcha_login' => true, 'security_captcha_register' => true, 'security_login_attempts' => 5]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'PUT', 'path' => '/admin/security', 'summary' => '更新安全配置', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'security_captcha_login', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '登录验证码开关'], ['name' => 'security_captcha_register', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '注册验证码开关'], ['name' => 'security_login_attempts', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '登录失败锁定次数']], 'request_example' => ['security_captcha_login' => 1, 'security_login_attempts' => 5], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/admin/security/captcha', 'summary' => '获取验证码配置', 'auth' => true, 'level' => 'Admin', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['captcha_type' => 'image', 'captcha_length' => 4, 'captcha_expire' => 300]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'PUT', 'path' => '/admin/security/captcha', 'summary' => '更新验证码配置', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'captcha_type', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '验证码类型'], ['name' => 'captcha_length', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '验证码长度'], ['name' => 'captcha_expire', 'type' => 'int', 'in' => 'body', 'required' => false, 'desc' => '验证码过期时间（秒）']], 'request_example' => ['captcha_type' => 'image', 'captcha_length' => 4], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/admin/audit/pending', 'summary' => '获取待审列表', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '类型：thread/post/profile'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 5, 'total_pages' => 1]]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'POST', 'path' => '/admin/audit/approve', 'summary' => '审核通过', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'target_type', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '目标类型：thread/post/profile'], ['name' => 'target_id', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '目标ID']], 'request_example' => ['target_type' => 'thread', 'target_id' => 1], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '目标不存在']],
                    ['method' => 'POST', 'path' => '/admin/audit/reject', 'summary' => '审核驳回', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'target_type', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '目标类型：thread/post/profile'], ['name' => 'target_id', 'type' => 'int', 'in' => 'body', 'required' => true, 'desc' => '目标ID'], ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '驳回原因']], 'request_example' => ['target_type' => 'thread', 'target_id' => 1, 'reason' => '内容违规'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '目标不存在']],
                    ['method' => 'POST', 'path' => '/admin/audit/batch-approve', 'summary' => '批量通过', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'target_type', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '目标类型：thread/post/profile'], ['name' => 'ids', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '目标ID数组']], 'request_example' => ['target_type' => 'thread', 'ids' => [1, 2, 3]], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['approved' => 3]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'POST', 'path' => '/admin/audit/batch-reject', 'summary' => '批量驳回', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'target_type', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '目标类型：thread/post/profile'], ['name' => 'ids', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '目标ID数组'], ['name' => 'reason', 'type' => 'string', 'in' => 'body', 'required' => false, 'desc' => '驳回原因']], 'request_example' => ['target_type' => 'thread', 'ids' => [1, 2], 'reason' => '内容违规'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['rejected' => 2]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'GET', 'path' => '/admin/sensitive-words', 'summary' => '获取敏感词列表', 'auth' => true, 'level' => 'Admin', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['words' => ['违规词1', '违规词2']]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'POST', 'path' => '/admin/sensitive-words', 'summary' => '添加敏感词', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'word', 'type' => 'string', 'in' => 'body', 'required' => true, 'desc' => '敏感词']], 'request_example' => ['word' => '违规词'], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'DELETE', 'path' => '/admin/sensitive-words', 'summary' => '清空敏感词', 'auth' => true, 'level' => 'Admin', 'params' => [], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'POST', 'path' => '/admin/sensitive-words/import', 'summary' => '批量导入敏感词', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'words', 'type' => 'array', 'in' => 'body', 'required' => true, 'desc' => '敏感词数组']], 'request_example' => ['words' => ['词1', '词2', '词3']], 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['imported' => 3]], 'errors' => [401 => '未授权', 403 => '禁止访问', 422 => '参数验证失败']],
                    ['method' => 'DELETE', 'path' => '/admin/sensitive-words/{word}', 'summary' => '删除指定敏感词', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'word', 'type' => 'string', 'in' => 'path', 'required' => true, 'desc' => '敏感词']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => null], 'errors' => [401 => '未授权', 403 => '禁止访问', 404 => '敏感词不存在']],
                    ['method' => 'GET', 'path' => '/admin/log/credits', 'summary' => '积分日志', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '用户ID'], ['name' => 'type', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '积分类型'], ['name' => 'date_start', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '开始日期'], ['name' => 'date_end', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '结束日期'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 100, 'total_pages' => 5]]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                    ['method' => 'GET', 'path' => '/admin/log/login', 'summary' => '登录日志', 'auth' => true, 'level' => 'Admin', 'params' => [['name' => 'uid', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '用户ID'], ['name' => 'success', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '是否成功：0/1'], ['name' => 'date_start', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '开始日期'], ['name' => 'date_end', 'type' => 'string', 'in' => 'query', 'required' => false, 'desc' => '结束日期'], ['name' => 'page', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '页码'], ['name' => 'pagesize', 'type' => 'int', 'in' => 'query', 'required' => false, 'desc' => '每页数量']], 'request_example' => null, 'response_example' => ['code' => 0, 'msg' => 'ok', 'data' => ['list' => [], 'pagination' => ['page' => 1, 'pagesize' => 20, 'total' => 200, 'total_pages' => 10]]], 'errors' => [401 => '未授权', 403 => '禁止访问']],
                ],
            ],
        ];
    }

    public static function getOpenApiSpec(): array {
        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => 'Xiuno BBS API',
                'version' => '1.0.0',
                'description' => "Xiuno BBS RESTful API v1\n\n认证方式：\n- 所有请求必须包含 X-App-Id 和 X-App-Secret 请求头（应用认证）\n- 用户级认证使用 Authorization: Bearer <token>\n- 权限级别：Public（仅应用凭证）、Authenticated（应用+用户令牌）、Admin（应用+管理员令牌）",
            ],
            'servers' => [['url' => '/api/v1']],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'AppAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-App-Id', 'description' => '应用ID，配合 X-App-Secret 使用'],
                    'BearerAuth' => ['type' => 'http', 'scheme' => 'bearer'],
                ],
                'schemas' => [
                    'Pagination' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer'], 'pagesize' => ['type' => 'integer'], 'total' => ['type' => 'integer'], 'total_pages' => ['type' => 'integer']]],
                    'ErrorResponse' => ['type' => 'object', 'properties' => ['code' => ['type' => 'integer'], 'msg' => ['type' => 'string'], 'data' => ['type' => 'object', 'nullable' => true]]],
                ],
            ],
        ];
        foreach (self::getEndpoints() as $group) {
            foreach ($group['endpoints'] as $ep) {
                $path = $ep['path'];
                $method = strtolower($ep['method']);
                if (!isset($spec['paths'][$path])) $spec['paths'][$path] = [];
                $operation = ['summary' => $ep['summary'], 'responses' => ['200' => ['description' => 'Success']]];
                if (!empty($ep['level'])) {
                    $operation['x-level'] = $ep['level'];
                }
                if ($ep['auth']) {
                    $operation['security'] = [['AppAuth' => [], 'BearerAuth' => []]];
                } else {
                    $operation['security'] = [['AppAuth' => []]];
                }
                if (!empty($ep['params'])) {
                    $operation['parameters'] = [];
                    foreach ($ep['params'] as $p) {
                        if ($p['in'] === 'path') $operation['parameters'][] = ['name' => $p['name'], 'in' => 'path', 'required' => true, 'schema' => ['type' => $p['type'] === 'int' ? 'integer' : 'string'], 'description' => $p['desc']];
                        elseif ($p['in'] === 'query') $operation['parameters'][] = ['name' => $p['name'], 'in' => 'query', 'required' => $p['required'], 'schema' => ['type' => $p['type'] === 'int' ? 'integer' : 'string'], 'description' => $p['desc']];
                    }
                }
                $spec['paths'][$path][$method] = $operation;
            }
        }
        return $spec;
    }

    public static function getErrorCodes(): array {
        return [
            ['code' => 0, 'desc' => '成功'],
            ['code' => 401, 'desc' => '未授权（Token 缺失或无效）'],
            ['code' => 403, 'desc' => '禁止访问（权限不足）'],
            ['code' => 404, 'desc' => '资源不存在'],
            ['code' => 405, 'desc' => '方法不允许'],
            ['code' => 409, 'desc' => '资源冲突（已存在）'],
            ['code' => 422, 'desc' => '参数验证失败'],
            ['code' => 429, 'desc' => '请求过于频繁'],
            ['code' => 500, 'desc' => '服务器内部错误'],
        ];
    }
}