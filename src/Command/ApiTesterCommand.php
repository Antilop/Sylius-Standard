<?php

namespace App\Command;

use Doctrine\ORM\EntityManager;
use OAuth2\OAuth2;
use Sylius\Bundle\AdminApiBundle\Model\Client;
use Sylius\Component\Core\Model\AdminUser;
use Sylius\Component\User\Security\UserPasswordEncoderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
        $this->em = $this->container->get('doctrine')->getManager();
        $client_repository = $this->em->getRepository(Client::class);
        $admin_repository = $this->em->getRepository(AdminUser::class);

        $client = $client_repository->find(1);
        $api_user = $admin_repository->findOneBy(['username' => 'api']);
        $this->getAccessToken($client, $api_user);
        $products = $this->getProducts();

        if (!is_null($products)) {
            $output->writeln('current page number: ' . $products->page);
            $output->writeln('search limit: ' . $products->limit);
            $output->writeln('number of pages: ' . $products->pages);
            $output->writeln('products count: ' . $products->total);
            $output->writeln('first product data: ' . json_encode($products->_embedded->items[0]));

            $output->writeln('<info>Api is available</info>');
            return 0;
        }

        // something wrong during requests
        return 1;
    }

    protected function getAccessToken($client, $api_user)
    {
        if (is_null($client)) {
            $client = new Client();
            $client->setAllowedGrantTypes([
                OAuth2::GRANT_TYPE_IMPLICIT,
                OAuth2::GRANT_TYPE_USER_CREDENTIALS,
                OAuth2::GRANT_TYPE_REFRESH_TOKEN
            ]);

            $this->em->persist($client);
            $this->em->flush();
        }

        if (is_null($api_user)) {
            // Création du user API si les fixtures n'ont pas été chargées.
            $api_user = new AdminUser();
            $api_user->setEmail('api@example.com');
            $api_user->setUsername('api');
            $api_user->setPlainPassword('sylius-api');
            $api_user->setPassword($this->userPasswordEncoder->encode($api_user));
            $api_user->eraseCredentials();
            $api_user->setEnabled(true);
            $api_user->setLocaleCode('%locale%');
            $api_user->setFirstName('Luke');
            $api_user->setLastName('Brushwood');
            $api_user->addRole('ROLE_ADMINISTRATION_ACCESS');
            $api_user->addRole('ROLE_API_ACCESS');

            $this->em->persist($api_user);
            $this->em->flush();
        }

        $public_id = $client->getPublicId();
        $secret = $client->getSecret();

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->router->generate('fos_oauth_server_token', [], UrlGeneratorInterface::ABSOLUTE_URL),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => [
                'client_id' => $public_id,
                'client_secret' => $secret,
                'grant_type' => 'password',
                'username' => $api_user->getUsername(),
                'password' => $api_user->getPassword()
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        $this->access_token_data = json_decode($response);
    }

    protected function getProducts()
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $this->router->generate('sylius_admin_api_product_index', ['version' => 2], UrlGeneratorInterface::ABSOLUTE_URL),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "Authorization: " . ucfirst($this->access_token_data->token_type) . ' ' . $this->access_token_data->access_token
            ],
        ]);

        $response = curl_exec($curl);

        curl_close($curl);

        return json_decode($response);
    }
}
