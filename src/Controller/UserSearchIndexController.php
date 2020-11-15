<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\FilterForm;
use App\Form\FilterFormType;
use DatingLibre\AppBundle\Controller\ProfilePaginator;
use DatingLibre\AppBundle\Service\FilterService;
use DatingLibre\AppBundle\Service\ProfileService;
use DatingLibre\AppBundle\Service\RequirementService;
use DatingLibre\AppBundle\Service\SuspensionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class UserSearchIndexController extends AbstractController
{
    private const DEFAULT_LIMIT = 10;
    private EntityManagerInterface $entityManager;
    private ProfileService $profileService;
    private FilterService $filterService;
    private SuspensionService $suspensionService;
    private RequirementService $requirementService;

    public function __construct(
        EntityManagerInterface $entityManager,
        ProfileService $profileService,
        FilterService $filterService,
        SuspensionService $suspensionService,
        RequirementService $requirementService
    )
    {
        $this->entityManager = $entityManager;
        $this->profileService = $profileService;
        $this->filterService = $filterService;
        $this->suspensionService = $suspensionService;
        $this->requirementService = $requirementService;
    }

    public function index(Request $request)
    {
        $userId = $this->getUser()->getId();
        $profile = $this->profileService->find($userId);

        if ($profile === null) {
            $this->addFlash('warning', 'profile.incomplete');
            return new RedirectResponse($this->generateUrl('user_profile_edit'));
        }

        if ($this->suspensionService->findOpenByUserId($userId) !== null) {
            return new RedirectResponse($this->generateUrl('user_profile_index'));
        }

        $filter = $this->filterService->create($userId);
        $filterForm = new FilterForm();
        $filterForm->setDistance($filter->getDistance());
        $filterForm->setMaxAge($filter->getMaxAge());
        $filterForm->setMinAge($filter->getMinAge());
        $filterForm->setRegion($filter->getRegion());
        $filterForm->setRelationships($this->requirementService->getMultipleByUserAndCategory($userId, 'relationship'));
        $filterForm->setSexes($this->requirementService->getMultipleByUserAndCategory($userId, 'sex'));
        $filterFormType = $this->createForm(
            FilterFormType::class,
            $filterForm,
            [
                'regions' => $profile->getCity()->getRegion()->getCountry()->getRegions(),
            ]
        );

        $filterFormType->handleRequest($request);

        if ($filterFormType->isSubmitted() && $filterFormType->isValid()) {
            $this->entityManager->transactional(function ($entityManager) use ($userId, $filter, $filterForm) {
                $filter->setRegion($filterForm->getRegion());
                $filter->setMinAge($filterForm->getMinAge());
                $filter->setMaxAge($filterForm->getMaxAge());
                $filter->setDistance($filterForm->getDistance());

                $this->requirementService->createRequirementsInCategory(
                    $userId,
                    'sex',
                    $filterForm->getSexes()
                );

                $this->requirementService->createRequirementsInCategory(
                    $userId,
                    'relationship',
                    $filterForm->getRelationships()
                );
            });

            return new RedirectResponse($this->generateUrl('user_search_index'));
        }

        $previous = (int) $request->query->get(ProfilePaginator::PREVIOUS, 0);
        $next = (int) $request->query->get(ProfilePaginator::NEXT, 0);

        $profiles = $this->profileService->findByLocation(
            $userId,
            $filter->getDistance(),
            empty($filter->getRegion()) ? null : $filter->getRegion()->getId(),
            $filter->getMinAge(),
            $filter->getMaxAge(),
            $previous,
            $next,
            self::DEFAULT_LIMIT
        );

        return $this->render('@DatingLibreApp/user/search/index.html.twig', [
            'next' => ProfilePaginator::getNext($profiles, $previous, self::DEFAULT_LIMIT),
            'previous' => ProfilePaginator::getPrevious($profiles, $next, self::DEFAULT_LIMIT),
            'page' => 'search',
            'profiles' => $profiles,
            'filterForm' => $filterFormType->createView()
        ]);
    }
}