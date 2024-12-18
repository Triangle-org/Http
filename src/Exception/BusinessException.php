<?php declare(strict_types=1);

/**
 * @package     Triangle HTTP Component
 * @link        https://github.com/Triangle-org/Http
 *
 * @author      Ivan Zorin <creator@localzet.com>
 * @copyright   Copyright (c) 2023-2024 Triangle Framework Team
 * @license     https://www.gnu.org/licenses/agpl-3.0 GNU Affero General Public License v3.0
 *
 *              This program is free software: you can redistribute it and/or modify
 *              it under the terms of the GNU Affero General Public License as published
 *              by the Free Software Foundation, either version 3 of the License, or
 *              (at your option) any later version.
 *
 *              This program is distributed in the hope that it will be useful,
 *              but WITHOUT ANY WARRANTY; without even the implied warranty of
 *              MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *              GNU Affero General Public License for more details.
 *
 *              You should have received a copy of the GNU Affero General Public License
 *              along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *              For any questions, please contact <triangle@localzet.com>
 */

namespace Triangle\Exception;

use RuntimeException;
use Throwable;
use Triangle\Http\Request;
use Triangle\Http\Response;
use function nl2br;
use function responseJson;
use function responseView;

/**
 * Класс BusinessException
 * Этот класс представляет собой пользовательское исключение, которое может быть использовано для обработки ошибок бизнес-логики.
 */
class BusinessException extends RuntimeException implements ExceptionInterface
{
    protected array $data = [];

    /**
     * Рендеринг исключения
     * Этот метод вызывается для отображения исключения пользователю.
     * @param Request $request Текущий HTTP-запрос
     * @return Response|null Ответ, который следует отправить пользователю
     * @throws Throwable
     */
    public function render(Request $request): ?Response
    {
        $json = [
            'status' => $this->getCode() ?? 500,
            'error' => $this->getMessage(),
            'data' => $this->data,
        ];

        if (config('app.debug')) {
            $json['debug'] = config('app.debug');
            $json['traces'] = nl2br((string)$this);
        }

        if ($request->expectsJson()) return responseJson($json);

        return responseView($json, 500);
    }

    /**
     * Установить доп. данные.
     * @param array $data
     * @return $this
     */
    public function setData(array $data): BusinessException
    {
        $this->data = $data;
        return $this;
    }

    /**
     * Получить доп. данные.
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param string $message
     * @param array $parameters
     * @param string|null $domain
     * @param string|null $locale
     * @return string
     */
    protected function trans(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        $args = [];
        foreach ($parameters as $key => $parameter) {
            $args[":$key"] = $parameter;
        }
        try {
            $message = trans($message, $args, $domain, $locale);
        } catch (Throwable $e) {
        }
        foreach ($parameters as $key => $value) {
            $message = str_replace(":$key", $value, $message);
        }
        return $message;
    }
}
