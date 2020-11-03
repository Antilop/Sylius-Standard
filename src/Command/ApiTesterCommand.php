<?php

namespace App\Command;

use Doctrine\ORM\EntityManager;
use OAuth2\OAuth2;
use Sylius\Bundle\AdminApiBundle\Model\Client;
use Sylius\Component\Core\Model\AdminUser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;

class ApiTesterCommand extends Command
{
    protected static $defaultName = 'antilop:api-tester';
    /** @var ContainerInterface */
    private $container;
    /** @var UrlGeneratorInterface */
    private $router;
    /** @var \stdClass */
    private $access_token_data;
    /** @var EntityManager $em */
    private $em;
    /** @var UserPasswordEncoderInterface */
    private $userPasswordEncoder;

    public function __construct(ContainerInterface $container, UrlGeneratorInterface $router, UserPasswordEncoderInterface $userPasswordEncoder)
    {
        parent::__construct();
        $this->router = $router;
        $this->container = $container;
        $this->userPasswordEncoder = $userPasswordEncoder;

        // Temporary: setting hostname for router
        $this->router->setContext($this->router->getContext()->setHost('nginx'));
    }

    protected function configure()
    {
        $this
            // the short description shown while running "php bin/console list"
            ->setDescription('Test if api is available');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->getAccessToken();
        if($this->access_token_data) {
            $products = $this->getProducts();

            if (!is_null($products)) {
                $output->writeln('products count: ' . $products->hits->total);
                $output->writeln('first product data: ' . json_encode($products->hits->hits[0]));

                $output->writeln('<info>Api is available</info>');
                return 0;
            }
        }

        // something wrong during requests
        $output->writeln('<error>Api Oauth failed</error>');
        return 1;
    }

    protected function getAccessToken()
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->router->generate('bitbag_vue_storefront_plugin_user_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => json_encode([
                "username" => "shop@example.com",
                "password" => "sylius"
            ]),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        $data = json_decode($response);

        if (!is_null($data) && $data->code === 200) {
            $this->access_token_data = $data->result;
        }
    }

    protected function getProducts()
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->router->generate('bitbag_vue_storefront_plugin_catalog_get_type', ['index' => getenv('ELASTICSEARCH_INDEX'), 'type'=> 'product'], UrlGeneratorInterface::ABSOLUTE_URL),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $this->access_token_data
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response);
    }
}
