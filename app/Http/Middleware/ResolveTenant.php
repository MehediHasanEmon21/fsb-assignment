<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ResolveTenant
{
    public const HEADER = 'X-Tenant-ID';

    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $tenantId = filter_var(
            $request->header(self::HEADER),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($tenantId === false) {
            throw new BadRequestHttpException('A valid X-Tenant-ID header is required.');
        }

        $this->context->set($this->resolver->resolve($user, $tenantId));

        try {
            return $next($request);
        } finally {
            $this->context->forget();
        }
    }
}
