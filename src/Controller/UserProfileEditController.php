<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ProfileForm;
use App\Form\ProfileFormType;
use DatingLibre\AppBundle\Service\ProfileService;
use DatingLibre\AppBundle\Service\SuspensionService;
use DatingLibre\AppBundle\Service\UserAttributeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class UserProfileEditController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private UserAttributeService $userAttributeService;
    private ProfileService $profileService;
    private SuspensionService $suspensionService;

    public function __construct(
        EntityManagerInterface $entityManager,
        ProfileService $profileService,
        UserAttributeService $userAttributeService,
        SuspensionService $suspensionService
    ) {
        $this->entityManager = $entityManager;
        $this->userAttributeService = $userAttributeService;
        $this->profileService = $profileService;
        $this->suspensionService = $suspensionService;
    }

    public function edit(Request $request)
    {
        $userId = $this->getUser()->getId();
        $profile = $this->profileService->create($userId);
        $profileProjection = $this->profileService->findProjection($userId);
        $permanentSuspension = $this->suspensionService->findOpenPermanentSuspension($userId);

        if ($permanentSuspension !== null) {
            return new RedirectResponse($this->generateUrl('user_profile_index'));
        }

        $profileForm = new ProfileForm();
        $city = $profile->getCity();

        if ($city !== null) {
            $profileForm->setCountry($city->getCountry());
            $profileForm->setRegion($city->getRegion());
            $profileForm->setCity($city);
        }

        $profileForm->setAbout($profile->getAbout());
        $profileForm->setUsername($profile->getUsername());
        $profileForm->setDob($profile->getDob());
        $profileForm->setSex($this->userAttributeService->getOneByCategoryName($userId, 'sex'));
        $profileForm->setRelationship($this->userAttributeService->getOneByCategoryName($userId, 'relationship'));
        $profileFormType = $this->createForm(ProfileFormType::class, $profileForm);
        $profileFormType->handleRequest($request);

        if ($profileFormType->isSubmitted() && $profileFormType->isValid()) {
            $this->entityManager->transactional(function($entityManager) use ($userId, $profile, $profileForm) {
                $this->userAttributeService->createUserAttributes(
                    $userId,
                    [$profileForm->getSex(), $profileForm->getRelationship()]
                );

                $profile->setCity($profileForm->getCity());
                $profile->setUsername($profileForm->getUsername());
                $profile->setAbout($profileForm->getAbout());
                $profile->setDob($profileForm->getDob());
            });

            return new RedirectResponse($this->generateUrl('user_profile_index'));
        }

        return $this->render('@DatingLibreApp/user/profile/edit.html.twig', [
            'profileForm' => $profileFormType->createView(),
            'profile' => $profileProjection,
        ]);
    }
}
