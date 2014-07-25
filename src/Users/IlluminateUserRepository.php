<?php namespace Cartalyst\Sentinel\Users;
/**
 * Part of the Sentinel package.
 *
 * NOTICE OF LICENSE
 *
 * Licensed under the Cartalyst PSL License.
 *
 * This source file is subject to the Cartalyst PSL License that is
 * bundled with this package in the license.txt file.
 *
 * @package    Sentinel
 * @version    1.0.0
 * @author     Cartalyst LLC
 * @license    Cartalyst PSL
 * @copyright  (c) 2011-2014, Cartalyst LLC
 * @link       http://cartalyst.com
 */

use Carbon\Carbon;
use Cartalyst\Sentinel\Hashing\HasherInterface;
use Cartalyst\Support\Traits\EventTrait;
use Cartalyst\Support\Traits\RepositoryTrait;
use Closure;
use Illuminate\Events\Dispatcher;
use InvalidArgumentException;

class IlluminateUserRepository implements UserRepositoryInterface {

	use EventTrait, RepositoryTrait;

	/**
	 * Hasher.
	 *
	 * @var \Cartalyst\Sentinel\Hashing\HasherInterface
	 */
	protected $hasher;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	protected $model = 'Cartalyst\Sentinel\Users\EloquentUser';

	/**
	 * Create a new Illuminate user repository.
	 *
	 * @param  \Cartalyst\Sentinel\Hashing\HasherInterface  $hasher
	 * @param  string  $model
	 * @return void
	 */
	public function __construct(
		HasherInterface $hasher,
		Dispatcher $dispatcher = null,
		$model = null
	)
	{
		$this->hasher     = $hasher;
		$this->dispatcher = $dispatcher;

		if (isset($model))
		{
			$this->model = $model;
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function findById($id)
	{
		return $this
			->createModel()
			->newQuery()
			->with('roles')
			->find($id);
	}

	/**
	 * {@inheritDoc}
	 */
	public function findByCredentials(array $credentials)
	{
		$instance = $this->createModel();

		$loginNames = $instance->getLoginNames();

		$query = $instance->newQuery()->with('roles');

		list($logins, $password, $credentials) = $this->parseCredentials($credentials, $loginNames);

		if (is_array($logins))
		{
			foreach ($logins as $key => $value)
			{
				$query->where($key, $value);
			}
		}
		else
		{
			$query->whereNested(function($query) use ($loginNames, $logins)
			{
				foreach ($loginNames as $name)
				{
					$query->orWhere($name, $logins);
				}
			});
		}

		return $query->first();
	}

	/**
	 * {@inheritDoc}
	 */
	public function findByPersistenceCode($code)
	{
		return $this->createModel()
			->newQuery()
			->with('roles')
			->whereHas('persistences', function($q) use ($code)
			{
				$q->where('code', $code);
			})
			->first();
	}

	/**
	 * {@inheritDoc}
	 */
	public function recordLogin(UserInterface $user)
	{
		$user->last_login = Carbon::now();

		return $user->save() ? $user : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function recordLogout(UserInterface $user)
	{
		return $user->save() ? $user : false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function validateCredentials(UserInterface $user, array $credentials)
	{
		return $this->hasher->check($credentials['password'], $user->password);
	}

	/**
	 * {@inheritDoc}
	 */
	public function validForCreation(array $credentials)
	{
		return $this->validateUser($credentials);
	}

	/**
	 * {@inheritDoc}
	 */
	public function validForUpdate($user, array $credentials)
	{
		if ($user instanceof UserInterface)
		{
			$user = $user->getUserId();
		}

		return $this->validateUser($credentials, $user);
	}

	/**
	 * {@inheritDoc}
	 */
	public function create(array $credentials, Closure $callback = null)
	{
		$user = $this->createModel();

		$this->fill($user, $credentials);

		if ($callback)
		{
			$result = $callback($user);

			if ($result === false)
			{
				return false;
			}
		}

		$user->save();

		return $user;
	}

	/**
	 * {@inheritDoc}
	 */
	public function update($user, array $credentials)
	{
		if ( ! $user instanceof UserInterface)
		{
			$user = $this->findById($user);
		}

		$this->fireEvent('sentinel.user.updating', compact('user', 'credentials'));

		$this->fill($user, $credentials);

		$user->save();

		$this->fireEvent('sentinel.user.updated', compact('user', 'credentials'));

		return $user;
	}

	/**
	 * Parses the given credentials to return logins, password and others.
	 *
	 * @param  array  $credentials
	 * @param  array  $loginNames
	 * @return array
	 * @throws \InvalidArgumentException
	 */
	protected function parseCredentials(array $credentials, array $loginNames)
	{
		if (isset($credentials['password']))
		{
			$password = $credentials['password'];

			unset($credentials['password']);
		}
		else
		{
			$password = null;
		}

		$passedNames = array_intersect_key($credentials, array_flip($loginNames));

		if (count($passedNames) > 0)
		{
			$logins = [];

			foreach ($passedNames as $name => $value)
			{
				$logins[$name] = $credentials[$name];
				unset($credentials[$name]);
			}
		}
		elseif (isset($credentials['login']))
		{
			$logins = $credentials['login'];
			unset($credentials['login']);
		}
		else
		{
			$logins = [];
		}

		return [$logins, $password, $credentials];
	}

	/**
	 * Validates the user.
	 *
	 * @param  array  $credentials
	 * @param  int  $id
	 * @return bool
	 * @throws \InvalidArgumentException
	 */
	protected function validateUser(array $credentials, $id = null)
	{
		$instance = $this->createModel();

		$loginNames = $instance->getLoginNames();

		// We will simply parse credentials which checks logins and passwords
		list($logins, $password, $credentials) = $this->parseCredentials($credentials, $loginNames);

		if ($id === null)
		{
			if (empty($logins))
			{
				throw new InvalidArgumentException('No [login] credential was passed.');
			}

			if ($password === null)
			{
				throw new InvalidArgumentException('You have not passed a [password].');
			}
		}

		return true;
	}

	/**
	 * Fills a user with the given credentials, intelligently.
	 *
	 * @param  \Cartalyst\Sentinel\Users\UserInterface  $user
	 * @param  array  $credentials
	 * @return void
	 */
	protected function fill(UserInterface $user, array $credentials)
	{
		$this->fireEvent('sentinel.user.filling', compact('user', 'credentials'));

		$loginNames = $user->getLoginNames();

		list($logins, $password, $attributes) = $this->parseCredentials($credentials, $loginNames);

		if (is_array($logins))
		{
			$user->fill($logins);
		}
		else
		{
			$loginName = reset($loginNames);

			$user->fill([
				$loginName => $logins,
			]);
		}

		$user->fill($attributes);

		if (isset($password))
		{
			$password = $this->hasher->hash($password);

			$user->fill(compact('password'));
		}

		$this->fireEvent('sentinel.user.filled', compact('user', 'credentials'));
	}

	/**
	 * Set the hasher.
	 *
	 * @param \Cartalyst\Sentinel\Hashing\HasherInterface  $hasher
	 * @return void
	 */
	public function setHasher(HasherInterface $hasher)
	{
		$this->hasher = $hasher;
	}

	/**
	 * Get the hasher.
	 *
	 * @param \Cartalyst\Sentinel\Hashing\HasherInterface  $hasher
	 * @return void
	 */
	public function getHasher()
	{
		return $this->hasher;
	}

}