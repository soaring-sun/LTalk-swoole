<?php
/**
 * Created by PhpStorm.
 * User: Yu
 * Date: 2018/4/13
 * Time: 17:22
 */

namespace App\HttpController\Api;


use App\Exception\LoginException;
use App\Exception\ParameterException;
use App\Exception\RegisterException;
use App\HttpController\Common;
use App\Model\User as UserModel;
use App\Service\LoginService;
use App\Service\RedisPoolService;
use App\Service\UserCacheService;
use App\Task\Task;
use App\Validate\LoginValidate;
use App\Validate\RegisterValidate;
use EasySwoole\Core\Http\AbstractInterface\Controller;
use EasySwoole\Core\Swoole\Coroutine\PoolManager;
use EasySwoole\Core\Swoole\Task\TaskManager;

class Login extends Controller
{
    public function index(){
        $this->response()->write('login');
    }

    /*
     * 用户注册
     */
    public function register(){
        // 验证
        (new RegisterValidate())->goCheck($this->request());
        $email = $this->request()->getRequestParam('email');
        $nickname = $this->request()->getRequestParam('nickname');
        $password = $this->request()->getRequestParam('password');
        $repassword = $this->request()->getRequestParam('repassword');

        // 判断两次密码是否输入一致（这块应该放到验证器中，但原生验证器并不支持自定义验证函数，后期优化
        if (strcmp($password,$repassword)){
            throw new ParameterException(['msg'=>'两次密码输入不一致']);
        }

        // 查询用户是否已经存在
        $user = UserModel::getUser(['email'=>$email]);
        if(!empty($user)){
            throw new RegisterException([
                'msg'=>'已有用户，请直接登录',
                'errorCode'=>20001
            ]);
        }

        // 生成唯一 LTalk number
        $number = Common::generate_code();
        while ( UserModel::getUser(['number'=>$number]) ){
            $number = Common::getRandChar();
        }

        // 入库
        $data = [
            'email' => $email,
            'password' => md5($password),
            'nickname' => $nickname,
            'number' => $number
        ];
        try{
            UserModel::newUser($data);
        }catch (\Exception $e){
            throw $e;
        }
        $this->writeJson(200, true);
    }


    /*
     * 用户登录
     * 验证通过后，将信息存入 redis
     * 返回 token
     */
    public function login(){
        (new LoginValidate())->goCheck($this->request());
        $email = $this->request()->getRequestParam('email');
        $password = $this->request()->getRequestParam('password');

        // 查询用户是否已经存在
        $user = UserModel::getUser(['email'=>$email]);
        if(empty($user)){
            throw new LoginException([
                'msg'=>'无效账号',
                'errorCode'=>30001
            ]);
        }

        // 查看用户是否已登录
        $isLogin = UserCacheService::getTokenByNum($user['number']);
        if($isLogin){
            throw new LoginException([
                'msg'=>'用户已登录',
                'errorCode'=>30003
            ]);
        }

        // 比较密码是否一致
        if (strcmp(md5($password),$user['password'])){
            throw new LoginException([
                'msg'=>'密码错误',
                'errorCode'=>30002
            ]);
        }

        // 更新登录时间
        $update = [
            'last_login' => time()
        ];
        UserModel::updateUser($user['id'], $update);
        // 生成 token
        $token = Common::getRandChar(16);

        // 将用户信息存入缓存
        $login_ser = new LoginService($token, $user);
        $login_ser->saveCache();

        // 返回 token
        $this->writeJson(200, $token);
    }


}