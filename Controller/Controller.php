<?php

/*
 * This file is part of the FOSJsRoutingBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\JsRoutingBundle\Controller;

use FOS\JsRoutingBundle\Extractor\ExposedRoutesExtractorInterface;
use FOS\JsRoutingBundle\Response\RoutesResponse;
use FOS\JsRoutingBundle\Util\CacheControlConfig;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Flash\AutoExpireFlashBag;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Controller class.
 *
 * @author William DURAND <william.durand1@gmail.com>
 */
class Controller
{
    /**
     * @var mixed
     */
    protected $serializer;

    /**
     * @var ExposedRoutesExtractorInterface
     */
    protected $exposedRoutesExtractor;

    /**
     * @var CacheControlConfig
     */
    protected $cacheControlConfig;

    /**
     * @var boolean
     */
    protected $debug;

    /**
     * @var FileLocatorInterface
     */
    protected $fileLocator;

    /**
     * Default constructor.
     *
     * @param object                          $serializer             Any object with a serialize($data, $format) method
     * @param ExposedRoutesExtractorInterface $exposedRoutesExtractor The extractor service.
     * @param array                           $cacheControl
     * @param boolean                         $debug
     */
    public function __construct($serializer, ExposedRoutesExtractorInterface $exposedRoutesExtractor, array $cacheControl = array(), $debug = false, RouterInterface $router, FileLocatorInterface $fileLocator)
    {
        $this->serializer             = $serializer;
        $this->exposedRoutesExtractor = $exposedRoutesExtractor;
        $this->cacheControlConfig     = new CacheControlConfig($cacheControl);
        $this->debug                  = $debug;
        $this->router = $router;
        $this->fileLocator = $fileLocator;
    }

    /**
     * indexAction action.
     */
    public function indexAction(Request $request, $group = 'default')
    {
        /*        $env = $this->container->getParameter('kernel.environment');*/
        $group = $request->get('group', 'default');
        $content = $this->getRouting($request, $group);

        $response = new Response($content, 200, array('Content-Type' => 'application/javascript'));

        if($cache = $this->getCache($group)){
            $response->setCache($cache);
        }
        return $response;
    }

    private function getExposedRoutesExtractor($group)
    {

        $exposedRoutes = new RouteCollection();

        $congFile = $this->fileLocator->locate('@AutocasionAppBundle/Resources/config/js_routing.yml');
        $configValues = Yaml::parse(file_get_contents($congFile));

        $routesToExpose = [];

        if(array_key_exists('js_routing',$configValues) && array_key_exists('routes_to_expose',$configValues['js_routing'])
            && is_array($configValues['js_routing']['routes_to_expose'])) {
            $routesToExpose = $configValues['js_routing']['routes_to_expose'];
        }
        $routerExposedRoutes = $this->router->getRouteCollection();
        foreach( $routerExposedRoutes as $routeName => $route){

            if(
                //$route->getOption('expose') == true // Si tiene el option.expose a true en la ruta
                (array_key_exists($routeName,$routesToExpose) && $routesToExpose[$routeName] === true)
                || (array_key_exists($routeName,$routesToExpose) && is_array($routesToExpose[$routeName])
                    && in_array($group, $routesToExpose[$routeName]))
            ){
                $exposedRoutes->add($routeName, $route);
            }
        }

        return $exposedRoutes;
    }

    private function getCache($group=null){

        if(null == $group){
            return null;
        }

        $congFile = $this->fileLocator->locate('@AutocasionAppBundle/Resources/config/js_routing.yml');
        $configValues = Yaml::parse(file_get_contents($congFile));

        if(array_key_exists('cache',$configValues['js_routing']) && array_key_exists($group, $configValues['js_routing']['cache'])){
            return $configValues['js_routing']['cache'][$group];
        }else{
            return null;
        }
    }

    private function getRouting(Request $request, $group){


        $routesResponse = new RoutesResponse(
            $this->exposedRoutesExtractor->getBaseUrl(),
            $this->getExposedRoutesExtractor($group),
            $this->getPrefix($request->getLocale()),
            $this->getHost(),
            $this->getScheme(),
            $request->getLocale()
        );

        $content = $this->serializer->serialize($routesResponse, 'json');
        if (null !== $callback = $request->query->get('callback')) {
            $validator = new \JsonpCallbackValidator();
            if (!$validator->validate($callback)) {
                throw new HttpException(400, 'Invalid JSONP callback value');
            }
            $content = '/**/' . $callback . '(' . $content . ');';
        }

        return $content;
    }


    private function getBaseUrl()
    {
        $env = $this->exposedRoutesExtractor->getBaseUrl('kernel.environment');

        /**
         * 2017-02-15 Problemas con este campo, hace que se caché url no seguras como app.php, ...
         */
        $base = '';

        // Solo usamos cache en redis si en env es 'prod'
        if ('prod' != $env) {
            $base = $this->router->getContext()->getBaseUrl();
        }

        return $base;
    }


    private function getPrefix($locale)
    {
        if (isset($this->bundles['JMSI18nRoutingBundle'])) {
            return $locale . I18nLoader::ROUTING_PREFIX;
        }

        return '';
    }

    private function getHost()
    {
        $requestContext = $this->router->getContext();

        $host = $requestContext->getHost();

        if ($this->usesNonStandardPort()) {
            $method = sprintf('get%sPort', ucfirst($requestContext->getScheme()));
            $host .= ':' . $requestContext->$method();
        }

        return $host;
    }

    private function getScheme()
    {
        return $this->router->getContext()->getScheme();
    }

    /**
     * Check whether server is serving this request from a non-standard port
     *
     * @return bool
     */
    private function usesNonStandardPort()
    {
        return $this->usesNonStandardHttpPort() || $this->usesNonStandardHttpsPort();
    }

    /**
     * Check whether server is serving HTTP over a non-standard port
     *
     * @return bool
     */
    private function usesNonStandardHttpPort()
    {
        return 'http' === $this->getScheme() && '80' != $this->router->getContext()->getHttpPort();
    }

    /**
     * Check whether server is serving HTTPS over a non-standard port
     *
     * @return bool
     */
    private function usesNonStandardHttpsPort()
    {
        return 'https' === $this->getScheme() && '443' != $this->router->getContext()->getHttpsPort();
    }

}
