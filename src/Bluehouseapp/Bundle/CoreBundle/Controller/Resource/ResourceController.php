<?php



namespace Bluehouseapp\Bundle\CoreBundle\Controller\Resource;

use FOS\RestBundle\Controller\FOSRestController;
use FOS\RestBundle\View\View;
use Hateoas\Configuration\Route;
use Hateoas\Representation\Factory\PagerfantaFactory;
use Bluehouseapp\Bundle\CoreBundle\Doctrine\ORM\RepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Hateoas\HateoasBuilder;
use Hateoas\Factory\EmbeddedsFactory;
/**
 * Base resource controller.
 *
 */
class ResourceController extends FOSRestController
{
    /**
     * @var Configuration
     */
    protected $config;

    /**
     * @var FlashHelper
     */
    protected $flashHelper;

    /**
     * @var DomainManager
     */
    protected $domainManager;

    /**
     * @var ResourceResolver
     */
    protected $resourceResolver;

    /**
     * @var RedirectHandler
     */
    protected $redirectHandler;

    /**
     * @var string
     */
    protected $stateMachineGraph;

    public function __construct(Configuration $config)
    {
        $this->config = $config;
    }

    public function getConfiguration()
    {
        return $this->config;
    }

    public function setContainer(ContainerInterface $container = null)
    {
        parent::setContainer($container);

        $this->resourceResolver = new ResourceResolver($this->config);
        if (null !== $container) {
            $this->redirectHandler = new RedirectHandler($this->config, $container->get('router'));

            if (!$this->config->isApiRequest()) {
                $this->flashHelper = new FlashHelper(
                    $this->config,
                    $container->get('translator'),
                    $container->get('session')
                );
            }

            $this->domainManager = new DomainManager(
                $container->get($this->config->getServiceName('manager')),
                $container->get('event_dispatcher'),
                $this->config,
                !$this->config->isApiRequest() ? $this->flashHelper : null
            );
        }
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function showAction(Request $request)
    {
        $criteria = $this->config->getCriteria();
        $view = $this
            ->view()
            ->setTemplate($this->config->getTemplate('show.html'))
            ->setTemplateVar($this->config->getResourceName())
            ->setData($this->findOr404($request,$criteria))
        ;

        return $this->handleView($view);
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function indexAction(Request $request)
    {
        $criteria = $this->config->getCriteria();
        $sorting = $this->config->getSorting();

        $repository = $this->getRepository();


        if ($this->config->isPaginated()) {
            $resources = $this->resourceResolver->getResource(
                $repository,
                'createPaginator',
                array($criteria, $sorting)
            );

            $resources->setCurrentPage($request->get('page', 1), true, true);
            $resources->setMaxPerPage($this->config->getPaginationMaxPerPage());

            if ($this->config->isApiRequest()) {
                $resources = $this->getPagerfantaFactory()->createRepresentation(
                    $resources,
                    new Route(
                        $request->attributes->get('_route'),
                        $request->attributes->get('_route_params')
                    )
                );
               // $hateoas = HateoasBuilder::create()->build();
                //$json = $hateoas->serialize($resources, 'json');
              //  $resources=$json;
            }
        } else {
            $resources = $this->resourceResolver->getResource(
                $repository,
                'findBy',
                array($criteria, $sorting, $this->config->getLimit())
            );
        }

#        echo $this->config->getPluralResourceName();
#        exit;
        $view = $this
            ->view()
            ->setTemplate($this->config->getTemplate('index.html'))
            ->setTemplateVar($this->config->getPluralResourceName())
            ->setData($resources)

        ;
        return $this->handleView($view);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function createAction(Request $request)
    {
        $resource = $this->createNew();
        $form = $this->getForm($resource);

        if ($form->handleRequest($request)->isValid()) {
            $resource = $this->domainManager->create($resource);

            if ($this->config->isApiRequest()) {
                return $this->handleView($this->view($resource));
            }

            if (null === $resource) {
                return $this->redirectHandler->redirectToIndex();
            }

            return $this->redirectHandler->redirectTo($resource);
        }

        if ($this->config->isApiRequest()) {
            return $this->handleView($this->view($form));
        }

        $view = $this
            ->view()
            ->setTemplate($this->config->getTemplate('create.html'))
            ->setData(array(
                $this->config->getResourceName() => $resource,
                'form'                           => $form->createView()
            ))
        ;

        return $this->handleView($view);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse|Response
     */
    public function updateAction(Request $request)
    {
        $resource = $this->findOr404($request);
        $form = $this->getForm($resource);
        $method = $request->getMethod();

        if (in_array($method, array('POST', 'PUT', 'PATCH')) &&
            $form->submit($request, !$request->isMethod('PATCH'))->isValid()) {
            $this->domainManager->update($resource);

            if ($this->config->isApiRequest()) {
                return $this->handleView($this->view($resource));
            }

            return $this->redirectHandler->redirectTo($resource);
        }

        if ($this->config->isApiRequest()) {
            return $this->handleView($this->view($form));
        }

        $view = $this
            ->view()
            ->setTemplate($this->config->getTemplate('update.html'))
            ->setData(array(
                $this->config->getResourceName() => $resource,
                'form'                           => $form->createView()
            ))
        ;

        return $this->handleView($view);
    }

    /**
     * @param Request $request
     *
     * @return RedirectResponse
     */
    public function deleteAction(Request $request)
    {
        $resource = $this->findOr404($request);
        $this->domainManager->delete($resource);

        if ($this->config->isApiRequest()) {
            return $this->handleView($this->view());
        }

        return $this->redirectHandler->redirectToIndex();
    }

    /**
     * @param Request $request
     * @param int     $version
     *
     * @return RedirectResponse
     */
    public function revertAction(Request $request, $version)
    {
        $resource   = $this->findOr404($request);
        $em         = $this->get('doctrine.orm.entity_manager');
        $repository = $em->getRepository('Gedmo\Loggable\Entity\LogEntry');
        $repository->revert($resource, $version);

        $this->domainManager->update($resource, 'revert');

        return $this->redirectHandler->redirectTo($resource);
    }

    public function moveUpAction(Request $request)
    {
        return $this->move($request, 1);
    }

    public function moveDownAction(Request $request)
    {
        return $this->move($request, -1);
    }



    /**
     * @return object
     */
    public function createNew()
    {
        return $this->resourceResolver->createResource($this->getRepository(), 'createNew');
    }

    /**
     * @param object|null $resource
     *
     * @return FormInterface
     */
    public function getForm($resource = null)
    {



        if ($this->config->isApiRequest()) {
            return $this->container->get('form.factory')->createNamed('', $this->config->getFormType(), $resource);
        }

        return $this->createForm($this->config->getFormType(), $resource);
    }

    /**
     * @param Request $request
     * @param array   $criteria
     *
     * @return object
     *
     * @throws NotFoundHttpException
     */
    public function findOr404(Request $request, array $criteria = array())
    {
        if ($request->get('slug')) {
            $default = array('slug' => $request->get('slug'));
        } elseif ($request->get('id')) {
            $default = array('id' => $request->get('id'));
        } else {
            $default = array();
        }

        $criteria= array_merge($default, $criteria);

        if (!$resource = $this->resourceResolver->getResource(
            $this->getRepository(),
            'findOneBy',
            array($criteria))
        ) {
            throw new NotFoundHttpException(
                sprintf(
                    'Requested %s does not exist with these criteria: %s.',
                    $this->config->getResourceName(),
                    json_encode($this->config->getCriteria($criteria))
                )
            );
        }

        return $resource;
    }

    /**
     * @return RepositoryInterface
     */
    public function getRepository()
    {
        return $this->get($this->config->getServiceName('repository'));
    }

    /**
     * @param Request $request
     * @param integer $movement
     *
     * @return RedirectResponse
     */
    protected function move(Request $request, $movement)
    {
        $resource = $this->findOr404($request);

        $this->domainManager->move($resource, $movement);

        return $this->redirectHandler->redirectToIndex();
    }

    /**
     * @return PagerfantaFactory
     */
    protected function getPagerfantaFactory()
    {
        return new PagerfantaFactory('page', 'paginate');
    }

    protected function handleView(View $view)
    {
        $handler = $this->get('fos_rest.view_handler');
       // $handler->setExclusionStrategyGroups($this->config->getSerializationGroups());

        if ($version = $this->config->getSerializationVersion()) {
       //     $handler->setExclusionStrategyVersion($version);
        }

        return $handler->handle($view);
    }




}
