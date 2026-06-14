<?php

declare(strict_types=1);

namespace VertoAD\Http\Action\Organizations;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VertoAD\Http\Action\Campaigns\CampaignRequestGuards;
use VertoAD\Http\Auth\RequestUserContext;
use VertoAD\Infrastructure\Security\ClientIpResolver;
use VertoAD\Repository\OrganizationMemberManagementRepositoryInterface;
use VertoAD\Repository\OrganizationMembershipRepositoryInterface;
use VertoAD\Service\AuditLogService;

final readonly class ManageOrganizationMembersAction
{
    public function __construct(
        private OrganizationMembershipRepositoryInterface $memberships,
        private OrganizationMemberManagementRepositoryInterface $memberManagement,
        private AuditLogService $audit,
        private ClientIpResolver $ipResolver,
    ) {
    }

    /** @param array<string, mixed> $args */
    public function invite(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $organizationId = $this->organizationId($args);
        if ($organizationId === null) {
            return $this->json($response, [
                'code' => 'invalid_organization',
                'message' => 'A positive organization_id path parameter is required.',
            ], 400);
        }

        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'A user-authenticated session is required for organization member management.',
            ], 401);
        }

        try {
            $body = $this->body($request);
            $member = $this->memberManagement->inviteMember(
                $organizationId,
                $this->stringField($body, 'email'),
                $this->stringField($body, 'role_id'),
            );
            $this->recordAudit($request, $context, $member, 'organization.member.invite');
        } catch (InvalidArgumentException $exception) {
            return $this->memberError($response, $exception);
        }

        return $this->json($response, ['member' => $member], 201);
    }

    /** @param array<string, mixed> $args */
    public function updateRole(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $organizationId = $this->organizationId($args);
        $memberId = $this->memberId($args);
        if ($organizationId === null || $memberId === null) {
            return $this->json($response, [
                'code' => 'invalid_organization_member',
                'message' => 'Positive organization_id and member_id path parameters are required.',
            ], 400);
        }

        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'A user-authenticated session is required for organization member management.',
            ], 401);
        }

        try {
            $member = $this->memberManagement->updateMemberRole(
                $organizationId,
                $memberId,
                $this->stringField($this->body($request), 'role_id'),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->memberError($response, $exception);
        }

        if ($member === null) {
            return $this->notFound($response);
        }

        $this->recordAudit($request, $context, $member, 'organization.member.update_role');

        return $this->json($response, ['member' => $member], 200);
    }

    /** @param array<string, mixed> $args */
    public function remove(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = RequestUserContext::fromRequest($request);
        $organizationId = $this->organizationId($args);
        $memberId = $this->memberId($args);
        if ($organizationId === null || $memberId === null) {
            return $this->json($response, [
                'code' => 'invalid_organization_member',
                'message' => 'Positive organization_id and member_id path parameters are required.',
            ], 400);
        }

        if ($context->user === null) {
            return $this->json($response, [
                'code' => 'authentication_required',
                'message' => 'A user-authenticated session is required for organization member management.',
            ], 401);
        }

        $member = $this->memberships->listForOrganization($organizationId);
        $removedMember = $this->findListedMember($member, $memberId);
        $removed = $this->memberManagement->removeMember($organizationId, $memberId);
        if (!$removed || $removedMember === null) {
            return $this->notFound($response);
        }

        $this->recordAudit($request, $context, $removedMember, 'organization.member.remove');

        return $this->json($response, ['removed' => true], 200);
    }

    /** @param array<string, mixed> $args */
    private function organizationId(array $args): ?int
    {
        return CampaignRequestGuards::positiveInteger($args['organization_id'] ?? null);
    }

    /** @param array<string, mixed> $args */
    private function memberId(array $args): ?int
    {
        return CampaignRequestGuards::positiveInteger($args['member_id'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringField(array $body, string $field): string
    {
        if (!array_key_exists($field, $body) || !is_string($body[$field])) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $field));
        }

        $value = trim($body[$field]);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $field));
        }

        return $value;
    }

    /**
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>|null
     */
    private function findListedMember(array $members, int $memberId): ?array
    {
        foreach ($members as $member) {
            if (($member['member_id'] ?? null) === $memberId) {
                return $member;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $member
     */
    private function recordAudit(
        ServerRequestInterface $request,
        RequestUserContext $context,
        array $member,
        string $action,
    ): void {
        $this->audit->record(
            action: $action,
            subjectType: 'organization_member',
            subjectId: (int) $member['member_id'],
            actorUserId: $context->user?->id,
            organizationId: (int) $member['organization_id'],
            ipAddress: $this->ipResolver->resolve($request),
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
            metadata: [
                'email' => $member['email'],
                'roles' => $member['roles'],
                'status' => $member['status'],
                'user_id' => $member['user_id'],
            ],
        );
    }

    private function memberError(ResponseInterface $response, InvalidArgumentException $exception): ResponseInterface
    {
        $message = $exception->getMessage();
        if ($message === 'Organization was not found.') {
            return $this->json($response, ['code' => 'invalid_organization', 'message' => $message], 404);
        }

        if ($message === 'Organization member already exists.') {
            return $this->json($response, ['code' => 'organization_member_conflict', 'message' => $message], 409);
        }

        return $this->json($response, ['code' => 'invalid_organization_member', 'message' => $message], 422);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, [
            'code' => 'organization_member_not_found',
            'message' => 'Organization member was not found in this organization scope.',
        ], 404);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response = $response->withStatus($statusCode)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response;
    }
}
