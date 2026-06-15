<?php

function filterFields(array $data, string $fieldsParam): array {
    if (empty($fieldsParam)) return $data;
    $allowed = array_flip(explode(',', $fieldsParam));
    if (is_array($data) && isset($data[0])) {
        return array_map(function($item) use ($allowed) {
            return array_intersect_key($item, $allowed);
        }, $data);
    }
    return array_intersect_key($data, $allowed);
}

function paginateResult(array $list, int $page, int $pagesize, int $total): array {
    return [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'pagesize' => $pagesize,
            'total' => $total,
            'total_pages' => $pagesize > 0 ? ceil($total / $pagesize) : 0,
        ],
    ];
}

// 解析查询参数
$period = isset($_GET['period']) ? $_GET['period'] : 'week';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$page_size = isset($_GET['page_size']) ? max(1, min(100, intval($_GET['page_size']))) : 20;
$fields = isset($_GET['fields']) ? $_GET['fields'] : '';

// 验证 period 参数
if (!in_array($period, ['week', 'month', 'all'])) {
    $period = 'week';
}

// 实例化排行榜服务
$rankService = new RankService($db);

switch ($method) {
    case 'GET':
        if (!isset($segments[1])) {
            // 未指定子资源，返回排行榜概览
            ApiResponse::success([
                'endpoints' => ['threads', 'users'],
                'params' => [
                    'period' => ['week', 'month', 'all'],
                    'page' => 'int',
                    'page_size' => 'int (1-100)',
                ],
            ]);
        } else {
            switch ($segments[1]) {
                case 'threads':
                    $result = $rankService->getHotThreads($period, $page, $page_size);
                    $paginated = paginateResult($result['list'], $page, $page_size, $result['total']);
                    if (!empty($fields)) {
                        $paginated['list'] = filterFields($paginated['list'], $fields);
                    }
                    ApiResponse::success($paginated);
                    break;

                case 'users':
                    $result = $rankService->getActiveUsers($period, $page, $page_size);
                    $paginated = paginateResult($result['list'], $page, $page_size, $result['total']);
                    if (!empty($fields)) {
                        $paginated['list'] = filterFields($paginated['list'], $fields);
                    }
                    ApiResponse::success($paginated);
                    break;

                default:
                    ApiResponse::notFound('Unknown rank type: ' . $segments[1]);
            }
        }
        break;

    default:
        ApiResponse::error(405, 'Method not allowed');
}
