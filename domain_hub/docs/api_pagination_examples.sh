#!/bin/bash
# API分页和搜索使用示例
# 适用于管理大量域名（1万+）的场景

# 配置你的API密钥
API_KEY="cfsd_xxxxxxxxxx"
API_SECRET="yyyyyyyyyyyy"
BASE_URL="https://your-domain.com/index.php?m=domain_hub"

# 颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}=== WHMCS域名分发插件 API使用示例 ===${NC}\n"

# 示例1：基础分页 - 获取第1页
echo -e "${YELLOW}示例1: 获取第1页（默认200条）${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&page=1" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .pagination'
echo ""

# 示例2：自定义每页数量
echo -e "${YELLOW}示例2: 获取第1页（每页50条）${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&page=1&per_page=50" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .pagination'
echo ""

# 示例3：搜索包含关键词的域名
echo -e "${YELLOW}示例3: 搜索包含'test'的域名${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&search=test&per_page=20" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, rootdomain, status}'
echo ""

# 示例4：按根域名过滤
echo -e "${YELLOW}示例4: 只查看example.com的域名${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&rootdomain=example.com&per_page=20" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, rootdomain}'
echo ""

# 示例5：按状态过滤
echo -e "${YELLOW}示例5: 只查看已暂停的域名${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&status=suspended&per_page=20" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, status}'
echo ""

# 示例6：按时间范围过滤
echo -e "${YELLOW}示例6: 查看2025年1月创建的域名${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&created_from=2025-01-01&created_to=2025-01-31&per_page=20" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, created_at}'
echo ""

# 示例7：按过期时间排序
echo -e "${YELLOW}示例7: 按过期时间升序（即将过期的在前）${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&sort_by=expires_at&sort_dir=asc&per_page=10" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, expires_at}'
echo ""

# 示例8：只返回指定字段（减少数据传输）
echo -e "${YELLOW}示例8: 只返回ID、域名和状态${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&fields=id,subdomain,rootdomain,status&per_page=10" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[]'
echo ""

# 示例9：组合查询
echo -e "${YELLOW}示例9: 组合查询（搜索test + active状态 + 按创建时间倒序）${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&search=test&status=active&sort_by=created_at&sort_dir=desc&per_page=10" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .count, .subdomains[] | {id, subdomain, status, created_at}'
echo ""

# 示例10：获取总数（可能较慢）
echo -e "${YELLOW}示例10: 获取总数（适用于显示总页数）${NC}"
curl -s -X GET \
  "${BASE_URL}&endpoint=subdomains&action=list&include_total=1&per_page=1" \
  -H "X-API-Key: ${API_KEY}" \
  -H "X-API-Secret: ${API_SECRET}" \
  | jq '.success, .pagination.total'
echo ""

# 示例11：遍历所有页（循环示例）
echo -e "${YELLOW}示例11: 遍历所有域名（自动分页）${NC}"
page=1
per_page=100
total_fetched=0

while true; do
    echo -e "${GREEN}正在获取第 $page 页...${NC}"
    
    response=$(curl -s -X GET \
        "${BASE_URL}&endpoint=subdomains&action=list&page=${page}&per_page=${per_page}" \
        -H "X-API-Key: ${API_KEY}" \
        -H "X-API-Secret: ${API_SECRET}")
    
    # 获取当前页数量
    count=$(echo "$response" | jq -r '.count')
    total_fetched=$((total_fetched + count))
    
    echo "  - 本页获取 ${count} 条，累计 ${total_fetched} 条"
    
    # 检查是否还有更多数据
    has_more=$(echo "$response" | jq -r '.pagination.has_more')
    
    # 如果没有更多数据，退出
    if [ "$has_more" != "true" ]; then
        echo -e "${GREEN}所有域名已获取完毕！总计: ${total_fetched} 条${NC}"
        break
    fi
    
    page=$((page + 1))
    
    # 防止触发速率限制
    sleep 0.2
    
    # 安全限制：最多获取100页
    if [ $page -gt 100 ]; then
        echo -e "${RED}达到最大页数限制（100页），停止获取${NC}"
        break
    fi
done
echo ""

echo -e "${GREEN}=== 所有示例执行完成 ===${NC}"
echo -e "${YELLOW}提示：${NC}"
echo "1. 修改脚本开头的 API_KEY 和 API_SECRET 为你的真实密钥"
echo "2. 修改 BASE_URL 为你的实际域名"
echo "3. 对于1万+域名，建议使用搜索和过滤功能定位目标域名"
echo "4. include_total=1 会执行COUNT查询，数据量大时较慢，仅在必要时使用"
echo "5. 使用 fields 参数可以减少数据传输量"
