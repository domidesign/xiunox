// XNX PWA Service Worker - 自动生成
const CACHE_VERSION = 'v1';
const STATIC_CACHE = 'xnx_pwa_' + CACHE_VERSION + '_static';
const PAGE_CACHE = 'xnx_pwa_' + CACHE_VERSION + '_pages';
const EXCLUDE_PATTERNS = [/admin\//, /api\//];
const PAGE_CACHE_MAX = 50;  // 页面缓存最大条数（LRU 淘汰）

// 离线页面 HTML
const OFFLINE_HTML = '<!DOCTYPE html><html lang=\"zh-cn\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\"><title>网络不可用<\/title><style>body{font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;background:#f8f9fa;color:#495057;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{background:#fff;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.08);padding:3rem;text-align:center;max-width:420px}.icon{font-size:3rem;color:#dc3545;margin-bottom:1rem}h1{font-size:1.5rem;margin:0 0 .5rem}p{margin:0;color:#6c757d}<\/style><\/head><body><div class=\"card\"><div class=\"icon\">&#128268;<\/div><h1>当前网络不可用<\/h1><p>请检查网络连接后重试<\/p><\/div><\/body><\/html>';

// install 事件：预缓存离线页面
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(function(cache) {
            var offlineResponse = new Response(OFFLINE_HTML, {
                headers: { 'Content-Type': 'text/html; charset=utf-8' }
            });
            return cache.put('?pwa-offline', offlineResponse);
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// activate 事件：清理旧版本缓存
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.map(function(key) {
                    // 只保留当前版本的缓存，清理其他版本的缓存
                    if (key.indexOf('xnx_pwa_') === 0 && key.indexOf(CACHE_VERSION) === -1) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// LRU 淘汰：页面缓存超过上限时删除最旧的条目
// cache.keys() 返回的顺序按插入时间，删除前 N 个即可
function trimPageCache(cache) {
    return cache.keys().then(function(keys) {
        if (keys.length <= PAGE_CACHE_MAX) {
            return;
        }
        var excess = keys.length - PAGE_CACHE_MAX;
        var deletePromises = [];
        for (var i = 0; i < excess; i++) {
            deletePromises.push(cache.delete(keys[i]));
        }
        return Promise.all(deletePromises);
    });
}

// fetch 事件：缓存策略
self.addEventListener('fetch', function(event) {
    // 只处理 GET 请求
    if (event.request.method !== 'GET') {
        return;
    }

    var url = event.request.url;

    // 检查 URL 是否匹配排除模式，匹配则直接走网络（不缓存）
    var i, len;
    for (i = 0, len = EXCLUDE_PATTERNS.length; i < len; i++) {
        if (EXCLUDE_PATTERNS[i].test(url)) {
            return;
        }
    }

    // 静态资源（.css/.js/.png/.jpg/.gif/.svg/.woff/.woff2/.ico）→ 缓存优先
    if (/\.(css|js|png|jpg|gif|svg|woff|woff2|ico)(\?|$)/i.test(url)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then(function(cache) {
                return cache.match(event.request).then(function(response) {
                    if (response) {
                        return response;
                    }
                    return fetch(event.request).then(function(response) {
                        // 只缓存成功的响应
                        if (response && response.status === 200) {
                            cache.put(event.request, response.clone());
                        }
                        return response;
                    }).catch(function() {
                        // 静态资源获取失败，返回空响应避免报错
                        return new Response('', { status: 404 });
                    });
                });
            })
        );
        return;
    }

    // HTML 页面（Accept: text/html）→ 网络优先，失败用缓存，都失败返回离线页面
    var acceptHeader = event.request.headers.get('accept');
    if (acceptHeader && acceptHeader.indexOf('text/html') !== -1) {
        event.respondWith(
            fetch(event.request).then(function(response) {
                // 网络成功，缓存副本
                if (response && response.status === 200) {
                    var responseClone = response.clone();
                    caches.open(PAGE_CACHE).then(function(cache) {
                        cache.put(event.request, responseClone).then(function() {
                            return trimPageCache(cache);
                        });
                    });
                }
                return response;
            }).catch(function() {
                // 网络失败，尝试从缓存读取
                return caches.match(event.request).then(function(response) {
                    if (response) {
                        return response;
                    }
                    // 缓存也没有，返回离线页面
                    return new Response(OFFLINE_HTML, {
                        headers: { 'Content-Type': 'text/html; charset=utf-8' }
                    });
                });
            })
        );
        return;
    }

    // 其他请求 → 直接走网络（不调用 event.respondWith，让浏览器处理）
});

// push 事件：接收推送通知并显示
self.addEventListener('push', function(event) {
    var payload = { title: '通知', body: '', url: '/', icon: '' };
    try {
        var data = event.data ? event.data.json() : {};
        if (data.title) payload.title = data.title;
        if (data.body) payload.body = data.body;
        if (data.url) payload.url = data.url;
        if (data.icon) payload.icon = data.icon;
        if (data.tag) payload.tag = data.tag;
    } catch (e) {
        // JSON 解析失败，尝试纯文本
        if (event.data) {
            payload.body = event.data.text();
        }
    }
    event.waitUntil(
        self.registration.showNotification(payload.title, {
            body: payload.body,
            icon: payload.icon,
            badge: payload.badge || '',
            tag: payload.tag || 'xnx-pwa',
            data: { url: payload.url },
            requireInteraction: false,
        })
    );
});

// notificationclick 事件：点击通知跳转
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var url = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/';
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            // 已有窗口则聚焦并跳转
            for (var i = 0; i < clientList.length; i++) {
                var client = clientList[i];
                if ('focus' in client) {
                    client.focus();
                    if ('navigate' in client) {
                        client.navigate(url);
                    }
                    return;
                }
            }
            // 没有窗口则打开新窗口
            if (self.clients.openWindow) {
                return self.clients.openWindow(url);
            }
        })
    );
});
