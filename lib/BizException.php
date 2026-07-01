<?php
/**
 * 业务异常类
 *
 * 用于表示可预期的业务错误（如参数校验失败、权限不足、限流等）。
 * 与系统异常区分：BizException 通过 ErrorHandler 返回 200 + 业务码，
 * 而系统异常（Throwable）返回 500，避免白屏。
 *
 * 用法：
 *   throw new BizException('余额不足', -1, array('balance' => 10));
 */
class BizException extends Exception
{
    /**
     * 附加数据，用于 JSON 响应或调试
     * @var array
     */
    public $data;

    /**
     * @param string $message 错误消息
     * @param int    $code    业务错误码（非 HTTP 状态码）
     * @param array  $data    附加数据
     */
    public function __construct($message = '', $code = 0, $data = array())
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
?>
