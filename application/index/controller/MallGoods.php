<?php

namespace app\index\controller;

use app\index\common\Oss;
use app\index\model\GoodsStockLogModel;
use app\index\model\MallGoodsModel;
use app\index\model\SystemAdmin;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use think\Cache;
use think\Db;
use think\Env;

class MallGoods extends BaseController
{
    public function getList()
    {
        $params = request()->post();
        $user = $this->user;
        $page = request()->post('page', 1);
        $limit = request()->post('limit', 15);
        $search_uid = request()->post('search_uid', 0);
        $where = [];
        if ($user['role_id'] != 1) {
            if ($user['role_id'] > 5) {
                $user['id'] = $user['parent_id'];
            }
            $where['g.uid'] = $user['id'];

        } else {
            if ($search_uid) {
                $where['g.uid'] = $search_uid;
            }
        }
        if (!empty($params['title'])) {
            $where['g.title'] = ['like', '%' . $params['title'] . '%'];
        }
        if (!empty($params['cate_id'])) {
            $str = ',' . $params['cate_id'][count($params['cate_id']) - 1] . ',';
            $where['g.cate_ids'] = ['like', '%' . $str . '%'];
        }
        if (!empty($params['mark'])) {
            $where['g.mark'] = ['=', $params['mark']];
        }
        $count = Db::name('mall_goods')->alias('g')
            ->join('system_admin a', 'g.uid=a.id', 'left')
            ->where($where)
            ->where('g.delete_time', null)
            ->count();
        $list = Db::name('mall_goods')->alias('g')
            ->join('system_admin a', 'g.uid=a.id', 'left')
            ->where($where)
            ->where('g.delete_time', null)
            ->page($page)
            ->limit($limit)
            ->field('g.*,a.username')
            ->order(['g.uid asc', 'g.id desc'])
            ->select();
        if ($list) {
            $cateModel = new \app\index\model\MallCate();
            $cateList = $cateModel
                ->where('status', 1)
                ->where('delete_time', null)
                ->column('name', 'id');
        } else {
            $list = [];
        }
        foreach ($list as $k => $v) {
            $list[$k]['cate_name'] = $cateModel->getName($cateList, $v['cate_ids']);
            $list[$k]['goods_images'] = $v['goods_images'] ? explode(',', $v['goods_images']) : [];
            $list[$k]['detail'] = $v['detail'] ? explode(',', $v['detail']) : [];
        }
        return json(['code' => 200, 'data' => $list, 'params' => $params, 'count' => $count]);
    }

    public function getAgentList()
    {
        $user = $this->user;
        $adminModel = new SystemAdmin();
        if ($user['role_id'] == 1) {
            $user = $adminModel->where('id', 1)->find();
        }

        $agent = $adminModel
            ->where('delete_time', null)
            ->where('account_type', 0)
            ->where('role_id', '<', 7)
            ->field('id,username,parent_id pid')
            ->select();

        $agent = $adminModel->getSon($agent, $user['id']);
        $admin = $adminModel
            ->whereIn('id', $agent)
            ->field('id,username')
            ->select();
        $data[] = ['id' => 0, 'username' => '全部'];
        $data[] = ['id' => $user['id'], 'username' => $user['username']];
        $list = array_merge($data, $admin);
        return json(['code' => 200, 'data' => $list]);
    }

    public function save()
    {
        $data = request()->post();
        $user = $this->user;
        $model = new \app\index\model\MallGoodsModel();
        $data['cate_ids'] = ',' . implode(',', $data['cate_ids']) . ',';
        $data['goods_images'] = implode(',', $data['goods_images']);
        if (empty($data['id'])) {
            $data['uid'] = $user['id'];
            $model->save($data);
        } else {
            $model->where('id', $data['id'])->update($data);
        }
        return json(['code' => 200, 'msg' => '成功']);
    }

    public function getOne()
    {
        $id = request()->get('id', '');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $data = Db::name('mall_goods')->alias('g')
            ->join('system_admin a', 'g.uid=a.id', 'left')
            ->where('g.id', $id)
            ->field('g.*,a.username')
            ->find();
        $data['cate_ids'] = $data['cate_ids'] ? explode(',', substr($data['cate_ids'], 1, -1)) : [];
        $data['detail'] = $data['detail'] ? explode(',', $data['detail']) : [];
        return json(['code' => 200, 'data' => $data]);
    }

    public function changeMark()
    {
        $id = request()->get('id', '');
        $mark = request()->get('mark', '');
        if (!$id || $mark === '') {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        Db::name('mall_goods')->where('id', $id)->update(['mark' => $mark]);
        return json(['code' => 200, 'msg' => '成功']);
    }

    //入库
    public function stockIn()
    {
        $params = request()->post();
        if (empty($params['id'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        if ($params['count'] < 1) {
            return json(['code' => 100, 'msg' => '入库数量不能小于1']);
        }
        $model = new MallGoodsModel();
        $stock = $model->where('id', $params['id'])->value('stock');
        $model->where('id', $params['id'])->update(['stock' => $stock + $params['count']]);
        $log = [
            'uid' => $this->user['id'],
            'goods_id' => $params['id'],
            'type' => 0,
            'count' => $params['count'],
            'stock' => $stock + $params['count']
        ];
        (new GoodsStockLogModel())->save($log);
        return json(['code' => 200, 'msg' => '入库成功']);
    }

    //出入库记录
    public function stockLog()
    {
        $params = request()->get();
        $id = request()->get('id', '');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $page = request()->get('page', 1);
        $limit = request()->get('limit', 10);

        $model = new GoodsStockLogModel();
        $count = $model->alias('log')
            ->join('system_admin a', 'a.id=log.uid', 'left')
            ->where('log.goods_id', $id)
            ->count();
        $list = $model->alias('log')
            ->join('system_admin a', 'a.id=log.uid', 'left')
            ->where('log.goods_id', $id)
            ->field('log.*,a.username')
            ->order('id desc')
            ->page($page)
            ->limit($limit)
            ->select();
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }

    public function del()
    {
        $id = request()->get('id', '');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $goods_id = Db::name('mall_goods')->where('id', $id)->value('goods_id');
        if ($goods_id > 0) {
            $user = $this->user;
            $uid = Db::name('system_goods')->where('id', $goods_id)->value('uid');
            $uid_arr = explode(',', substr($uid, 1, -1));
            foreach ($uid_arr as $k => $v) {
                if ($v == $user['id']) {
                    unset($uid_arr[$k]);
                }
            }
            $uid = $uid_arr ? ',' . implode(',', $uid_arr) . ',' : '';
            Db::name('system_goods')->where('id', $goods_id)->update(['uid' => $uid]);
        }
        Db::name('mall_goods')->where('id', $id)->delete();
        return json(['code' => 200, 'msg' => '删除成功']);
    }

    public function import()
    {
        $cate_ids = request()->post('cate_ids/a');
        if (empty($cate_ids)) {
            return json(['code' => 100, 'msg' => '请选择分类']);
        }
        $cate_ids = ',' . implode(',', $cate_ids) . ',';
        if ($_FILES) {
            $user = $this->user;
            header("content-type:text/html;charset=utf8");//如果是接口,去掉
            date_default_timezone_set('PRC');

            $path = dirname(dirname(dirname(dirname(__FILE__))));
            $geshi = array("xls", "xlsx");
            if ($_FILES['file']["error"]) {
                $data = [
                    'code' => 100,
                    'msg' => '文件上传错误'
                ];
                return json($data);
            } else {
                $file_geshi = explode('.', $_FILES["file"]["name"]);
                $this_ge = array_pop($file_geshi);
                if (!in_array($this_ge, $geshi)) {
                    $data = [
                        'code' => 100,
                        'msg' => '文件格式不正确'
                    ];
                    return json($data);
                }
                $dirpath = $path . "/public/upload/excel/";
                if (!is_dir($dirpath)) {
                    mkdir($dirpath, 0777, true);
                }
                $filename = $path . "/public/upload/excel/" . time() . '.' . $this_ge;
                move_uploaded_file($_FILES["file"]["tmp_name"], $filename);
            }
            $file = $filename;//读取的excel文件
            $data = $this->importExcel($file);
            if ($data['code'] == 1) {
                unlink($filename);
                $data = [
                    'code' => 100,
                    'msg' => $data['msg']
                ];
                return json($data);
            }
            if ($data['data']) {
                $arr = [];
//                $servername = Env::get('server.servername', 'http://api.feishi.vip');
                $mark = [
                    '正常' => 0,
                    '特价' => 1,
                    '精选' => 2,
                    'HOT' => 3,
                ];
                $oss = new Oss();
                foreach ($data['data'] as $k => $v) {
                    if ($k == 0) {
                        if ($v[0] != '商品名称' || $v[1] != '描述' || $v[2] != '价格' || $v[3] != '商品图' || $v[4] != '详情图' || $v[5] != '标记' || $v[6] != '备注') {
                            return json(['code' => 100, 'msg' => '请使用模板表格']);
                        }
                        continue;
                    }
                    $res = Db::name('mall_goods')->where('delete_time', null)->where(['uid' => $user['id'], 'title' => $v[0]])->find();
                    if (!$res && $v[0]) {
                        $arr[$k]['title'] = $v[0];
                        $arr[$k]['uid'] = $user['id'];
                        $arr[$k]['description'] = $v[1];
                        $arr[$k]['price'] = $v[2];
                        $oss_image_path = $v[3] ? "material" . strrchr($v[3], "/") : '';
                        $oss_detail_path = $v[4] ? "material" . strrchr($v[4], "/") : '';
                        $arr[$k]['image'] = $v[3] ? ($oss->uploadToOss($oss_image_path, $v[3]) ?? '') : '';
                        $arr[$k]['detail'] = $v[4] ? ($oss->uploadToOss($oss_detail_path, $v[4]) ?? '') : '';
                        $arr[$k]['mark'] = empty($mark[$v[5]]) ? 0 : $mark[$v[5]];
                        $arr[$k]['remark'] = $v[6];
                        $arr[$k]['cate_ids'] = $cate_ids;
                        $res = Db::name('mall_goods')->insert($arr[$k]);
                    }
                }
                unlink($filename);
                $data = [
                    'code' => 200,
                    'msg' => '导入成功'
                ];
                return json($data);
            } else {
                unlink($filename);
                $data = [
                    'code' => 100,
                    'msg' => '导入失败,文件为空'
                ];
                return json($data);
            }
        } else {
            $data = [
                'code' => 100,
                'msg' => '请选择文件'
            ];
            return json($data);
        }


    }

    //解析Excel文件
    function importExcel($file = '', $sheet = 0, $columnCnt = 0, &$options = [])
    {
        /* 转码 */
//        $file = iconv("utf-8", "gb2312", $file);
        if (empty($file) or !file_exists($file)) {
            $res = [
                'code' => 1,
                'msg' => '文件不存在'
            ];
            return $res;
        }
//        include_once VENDOR_PATH . 'phpoffice/phpexcel/Classes/PHPExcel/IOFactory.php';
        $inputFileType = \PHPExcel_IOFactory::identify($file);
//        $objReader = \PHPExcel_IOFactory::createReader($inputFileType);
        $objReader = IOFactory::createReader('Xlsx');
        $objPHPExcel = $objReader->load($file);
        $sheet = $objPHPExcel->getSheet(0);
        $data = $sheet->toArray(); //该方法读取不到图片，图片需单独处理

        $path = dirname(dirname(dirname(dirname(__FILE__))));
        $name = "/public/upload/" . date('Ymd') . '/goods_image/';
        $imageFilePath = $path . $name;
        if (!file_exists($imageFilePath)) { //如果目录不存在则递归创建
            mkdir($imageFilePath, 0777, true);
        }
        foreach ($sheet->getDrawingCollection() as $drawing) {
            list($startColumn, $startRow) = Coordinate::coordinateFromString($drawing->getCoordinates());
            $imageFileName = $drawing->getCoordinates() . time() . mt_rand(1000, 9999);
//            if ($drawing instanceof \PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing) {
            switch ($drawing->getExtension()) {
                case 'jpg':
                case 'jpeg':
                    $imageFileName .= '.jpg';
                    $source = imagecreatefromjpeg($drawing->getPath());
                    imagejpeg($source, $imageFilePath . $imageFileName);
                    break;
                case 'gif':
                    $imageFileName .= '.gif';
                    $source = imagecreatefromgif($drawing->getPath());
                    imagegif($source, $imageFilePath . $imageFileName);
                    break;
                case 'png':
                    $imageFileName .= '.png';
                    $source = imagecreatefrompng($drawing->getPath());
                    imagepng($source, $imageFilePath . $imageFileName);
                    break;
            }
//            } else {
//                $zipReader = fopen($drawing->getPath(), 'r');
//                $imageContents = '';
//                while (!feof($zipReader)) {
//                    $imageContents .= fread($zipReader, 2048);
//                }
//                fclose($zipReader);
//                $imageFileName .= $drawing->getExtension();
//            }
//            ob_start();
//            call_user_func(
//                $drawing->getRenderingFunction(),
//                $drawing->getImageResource()
//            );
//            $imageContents = ob_get_contents();
//            file_put_contents($imageFilePath . $imageFileName, $imageContents); //把图片保存到本地（上方自定义的路径）
//            ob_end_clean();

            $startColumn = $this->ABC2decimal($startColumn);
            $data[$startRow - 1][$startColumn] = $imageFilePath . $imageFileName;
        }
        $res = [
            'code' => 0,
            'data' => $data
        ];
        return $res;

    }

    public function ABC2decimal($abc)
    {
        $ten = 0;
        $len = strlen($abc);
        for ($i = 1; $i <= $len; $i++) {
            $char = substr($abc, 0 - $i, 1);//反向获取单个字符
            $int = ord($char);
            $ten += ($int - 65) * pow(26, $i - 1);
        }
        return $ten;
    }
}
